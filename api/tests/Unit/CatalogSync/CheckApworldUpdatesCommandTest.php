<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\Command\CheckApworldUpdatesService;
use App\CatalogSync\Application\Command\SubmitAvailableApworldUpdates;
use App\CatalogSync\Application\Message\SubmitAutoApworldUpdateJob;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\CatalogSync\Presentation\Command\CheckApworldUpdatesCommand;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Tests\Unit\GameSelection\InMemoryApworldCandidateRepository;
use App\Tests\Unit\GameSelection\InMemoryApworldIncidentRepository;
use App\Tests\Unit\GameSelection\SpyMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 38.11: the automatic update only ever submitted from the nightly pass, so a quiet server could
 * not be put to use - `app:check-apworld-updates` checked and stopped there. `--submit` runs the same
 * pass on demand, and `--limit` replaces the nightly cap for that run.
 */
final class CheckApworldUpdatesCommandTest extends TestCase
{
    private SpyMessageBus $bus;

    public function testWithoutSubmitNothingIsQueued(): void
    {
        $tester = $this->tester(nightlyCap: 10);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertSame([], $this->bus->messages(), 'a plain check stays a check');
        self::assertStringContainsString('3 update(s) available', $tester->getDisplay());
        self::assertStringContainsString('--submit', $tester->getDisplay(), 'the way to apply them is shown');
    }

    public function testSubmitRunsTheNightlyPassNow(): void
    {
        $tester = $this->tester(nightlyCap: 2);

        self::assertSame(Command::SUCCESS, $tester->execute(['--submit' => true]));

        self::assertCount(2, $this->bus->messages(), 'the nightly cap applies by default');
        self::assertContainsOnlyInstancesOf(SubmitAutoApworldUpdateJob::class, $this->bus->messages());
        self::assertStringContainsString('2 queued', $tester->getDisplay());
        self::assertStringContainsString('1 deferred', $tester->getDisplay());
    }

    public function testLimitReplacesTheNightlyCapForThisRun(): void
    {
        $tester = $this->tester(nightlyCap: 1);

        self::assertSame(Command::SUCCESS, $tester->execute(['--submit' => true, '--limit' => '3']));

        self::assertCount(3, $this->bus->messages());
    }

    public function testAnOutOfRangeLimitIsRefused(): void
    {
        foreach (['0', '201', 'abc'] as $limit) {
            $tester = $this->tester(nightlyCap: 1);

            self::assertSame(Command::INVALID, $tester->execute(['--submit' => true, '--limit' => $limit]), $limit);
            self::assertSame([], $this->bus->messages(), $limit);
        }
    }

    public function testLimitWithoutSubmitIsRefused(): void
    {
        $tester = $this->tester(nightlyCap: 1);

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '3']));
    }

    private function tester(int $nightlyCap): CommandTester
    {
        $games = [];
        foreach (['aaa', 'bbb', 'ccc'] as $repo) {
            $game = Game::create(ucfirst($repo), $repo, 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
            $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/'.$repo, deployedVersion: '1.0.0');
            $games[] = $game;
        }

        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $repo = (string) preg_replace('#^https://api\.github\.com/repos/owner/([^/]+)/.*$#', '$1', $url);

            return new MockResponse((string) json_encode([[
                'tag_name' => 'v2.0.0',
                'name' => 'v2.0.0',
                'published_at' => '2026-09-20T10:00:00Z',
                'html_url' => 'https://github.com/owner/'.$repo.'/releases/tag/v2.0.0',
                'draft' => false,
                'prerelease' => false,
                'assets' => [['name' => 'world.apworld', 'browser_download_url' => 'https://github.com/owner/'.$repo.'/releases/download/v2.0.0/world.apworld', 'size' => 1]],
            ]]), ['response_headers' => ['x-ratelimit-remaining' => ['4000']]]);
        });
        $checker = new ApworldVersionChecker($client, new NullLogger(), 'ghp_test_token');

        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findAllSortedByName')->willReturn($games);
        $repository->method('findById')->willReturnCallback(static function (string $id) use ($games): ?Game {
            foreach ($games as $game) {
                if ($game->getId() === $id) {
                    return $game;
                }
            }

            return null;
        });

        $incidents = new InMemoryApworldIncidentRepository();
        $this->bus = new SpyMessageBus($incidents);
        $clock = new MockClock('2026-09-27 12:00:00+00:00');

        return new CommandTester(new CheckApworldUpdatesCommand(
            new CheckApworldUpdatesService($checker, $repository, new NullLogger(), $clock),
            new SubmitAvailableApworldUpdates($repository, new InMemoryApworldCandidateRepository(), $checker, new RecordApworldIncident($incidents, $clock), $incidents, $this->bus, new NullLogger(), $nightlyCap),
        ));
    }
}
