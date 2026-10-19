<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\Command\CheckApworldUpdatesService;
use App\CatalogSync\Application\Command\QueueApworldUpdateCheck;
use App\CatalogSync\Application\Command\SubmitAvailableApworldUpdates;
use App\CatalogSync\Application\Handler\CheckApworldUpdatesHandler;
use App\CatalogSync\Application\Message\CheckApworldUpdatesMessage;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\CatalogSync\Domain\Entity\ApworldUpdateCheckRun;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Tests\Unit\GameSelection\InMemoryApworldCandidateRepository;
use App\Tests\Unit\GameSelection\InMemoryApworldIncidentRepository;
use App\Tests\Unit\GameSelection\SpyMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 38.18: the button queues a pass that only checks, the worker records it from start to end, and two
 * passes never run at once.
 */
final class ApworldUpdateCheckPassTest extends TestCase
{
    private MockClock $clock;
    private InMemoryApworldUpdateCheckRunRepository $runs;
    private SpyMessageBus $bus;
    private Game $behind;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-19 10:00:00+02:00');
        $this->runs = new InMemoryApworldUpdateCheckRunRepository();
        $this->bus = new SpyMessageBus(new InMemoryApworldIncidentRepository());
        $this->behind = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->behind->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/crystal', deployedVersion: 'CrystalProject-v0.17.0');
    }

    public function testTheButtonQueuesACheckForTheWorker(): void
    {
        $result = $this->queue()->queue();

        self::assertTrue($result->queued);
        $run = $result->run;
        self::assertNotNull($run);
        self::assertSame(ApworldUpdateCheckRun::STATUS_QUEUED, $run->status);
        self::assertSame(ApworldUpdateCheckRun::TRIGGER_BUTTON, $run->trigger);
        self::assertEquals([new CheckApworldUpdatesMessage($run->id, submit: false)], $this->bus->messages());
        self::assertNull($this->behind->getApworldCheckedAt(), 'nothing is checked in the request');
    }

    public function testASecondClickDuringAPassQueuesNothing(): void
    {
        $this->queue()->queue();

        $second = $this->queue()->queue();

        self::assertFalse($second->queued);
        self::assertCount(1, $this->runs->all());
        self::assertCount(1, $this->bus->messages());
    }

    public function testAPassThatStoppedMovingNoLongerBlocksTheButton(): void
    {
        $first = $this->queue()->queue()->run?->id;
        $this->clock->sleep(31 * 60);

        $second = $this->queue()->queue();

        self::assertTrue($second->queued);
        self::assertSame(ApworldUpdateCheckRun::STATUS_FAILED, $this->runs->findById((string) $first)?->getStatus());
    }

    public function testTheButtonsPassChecksRecordsItsReportAndNeverSubmits(): void
    {
        $runId = (string) $this->queue()->queue()->run?->id;
        $this->bus->dispatched = [];

        $this->handler()(new CheckApworldUpdatesMessage($runId, submit: false));

        $run = $this->runs->findById($runId);
        self::assertSame(ApworldUpdateCheckRun::STATUS_DONE, $run?->getStatus());
        self::assertSame(1, $run->getChecked());
        self::assertSame(1, $run->getTotal());
        self::assertSame(1, $run->getUpdatesAvailable());
        self::assertNotNull($run->getFinishedAt());
        self::assertNull($this->runs->findActive(), 'a finished pass frees the slot');
        self::assertSame([], $this->bus->messages(), 'only the night submits');
    }

    public function testTheNightlyPassIsRecordedAndSubmits(): void
    {
        $this->handler()(new CheckApworldUpdatesMessage());

        $run = $this->runs->findLatest();
        self::assertSame(ApworldUpdateCheckRun::TRIGGER_NIGHTLY, $run?->getTrigger());
        self::assertSame(ApworldUpdateCheckRun::STATUS_DONE, $run->getStatus());
        self::assertCount(1, $this->bus->messages(), 'the update found is submitted');
    }

    public function testTheNightTakesOverAClickStillWaitingInTheQueue(): void
    {
        $clickId = (string) $this->queue()->queue()->run?->id;
        $this->bus->dispatched = [];
        $this->clock->sleep(60);

        $this->handler()(new CheckApworldUpdatesMessage());
        $this->handler()(new CheckApworldUpdatesMessage($clickId, submit: false));

        self::assertSame(ApworldUpdateCheckRun::STATUS_FAILED, $this->runs->findById($clickId)?->getStatus());
        self::assertSame(ApworldUpdateCheckRun::TRIGGER_NIGHTLY, $this->runs->findLatest()?->getTrigger());
        self::assertCount(2, $this->runs->all(), 'the late click message does not start another pass');
    }

    public function testAPassAlreadyRunningElsewhereClosesTheQueuedClick(): void
    {
        $runId = (string) $this->queue()->queue()->run?->id;

        $this->handler(new FreePassLock(heldElsewhere: true))(new CheckApworldUpdatesMessage($runId, submit: false));

        $run = $this->runs->findById($runId);
        self::assertNotNull($run);
        self::assertSame(ApworldUpdateCheckRun::STATUS_FAILED, $run->getStatus());
        self::assertSame('Une autre vérification tournait déjà.', $run->getError());
    }

    public function testAPassThatCrashesIsRecordedAsFailedAndReleasesTheLock(): void
    {
        $runId = (string) $this->queue()->queue()->run?->id;
        $lock = new FreePassLock();
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findAllSortedByName')->willThrowException(new \RuntimeException('database gone'));

        $this->handler($lock, $games)(new CheckApworldUpdatesMessage($runId, submit: false));

        $run = $this->runs->findById($runId);
        self::assertNotNull($run);
        self::assertSame(ApworldUpdateCheckRun::STATUS_FAILED, $run->getStatus());
        self::assertSame('database gone', $run->getError());
        self::assertSame(1, $lock->releases);
    }

    private function queue(): QueueApworldUpdateCheck
    {
        return new QueueApworldUpdateCheck($this->runs, $this->bus, $this->clock);
    }

    private function handler(?FreePassLock $lock = null, ?GameRepositoryInterface $games = null): CheckApworldUpdatesHandler
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([[
            'tag_name' => 'CrystalProject-v0.18.2',
            'name' => 'CrystalProject-v0.18.2',
            'published_at' => '2026-09-11T22:15:28Z',
            'html_url' => 'https://github.com/owner/crystal/releases/tag/CrystalProject-v0.18.2',
            'draft' => false,
            'prerelease' => false,
            'assets' => [['name' => 'world.apworld', 'browser_download_url' => 'https://github.com/owner/crystal/releases/download/CrystalProject-v0.18.2/world.apworld', 'size' => 1]],
        ]]), ['response_headers' => ['x-ratelimit-remaining' => ['4000']]]));
        $checker = new ApworldVersionChecker($client, new NullLogger(), 'ghp_test_token');

        if (null === $games) {
            $games = self::createStub(GameRepositoryInterface::class);
            $games->method('findAllSortedByName')->willReturn([$this->behind]);
            $games->method('findById')->willReturn($this->behind);
        }
        $incidents = new InMemoryApworldIncidentRepository();

        return new CheckApworldUpdatesHandler(
            new CheckApworldUpdatesService($checker, $games, new NullLogger(), $this->clock),
            new SubmitAvailableApworldUpdates($games, new InMemoryApworldCandidateRepository(), $checker, new RecordApworldIncident($incidents, $this->clock), $incidents, $this->bus, new NullLogger(), 10),
            $this->runs,
            $lock ?? new FreePassLock(),
            $this->clock,
            new NullLogger(),
        );
    }
}
