<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\Command\ApworldUpdateAvailable;
use App\CatalogSync\Application\Command\SubmitAvailableApworldUpdates;
use App\CatalogSync\Application\Message\SubmitAutoApworldUpdateJob;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Tests\Unit\GameSelection\InMemoryApworldCandidateRepository;
use App\Tests\Unit\GameSelection\InMemoryApworldIncidentRepository;
use App\Tests\Unit\GameSelection\SpyMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SubmitAvailableApworldUpdatesTest extends TestCase
{
    /** @var array<string, Game> */
    private array $games = [];
    private InMemoryApworldCandidateRepository $candidates;
    private InMemoryApworldIncidentRepository $incidents;
    private SpyMessageBus $bus;

    protected function setUp(): void
    {
        $this->candidates = new InMemoryApworldCandidateRepository();
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->bus = new SpyMessageBus($this->incidents);
    }

    public function testQueuesOneSubmissionPerUpdateUpToTheNightlyCap(): void
    {
        // 233 updates were pending on the first real run: without a cap the first night would send
        // them all to the orchestrator and to Discord at once.
        $updates = [$this->update('aaa', 'v2'), $this->update('bbb', 'v2'), $this->update('ccc', 'v2')];

        $report = $this->submitter(cap: 2)->submit($updates);

        self::assertEquals([
            new SubmitAutoApworldUpdateJob($this->games['aaa']->getId(), 'v2', 'https://github.com/owner/aaa/releases/download/v2/world.apworld', 'world.apworld'),
            new SubmitAutoApworldUpdateJob($this->games['bbb']->getId(), 'v2', 'https://github.com/owner/bbb/releases/download/v2/world.apworld', 'world.apworld'),
        ], $this->bus->messages());
        self::assertSame(2, $report->queued);
        self::assertSame(1, $report->deferred, 'the rest waits for the next nights');
    }

    public function testSkipsAGameWhoseCandidateIsAlreadyInTest(): void
    {
        $update = $this->update('aaa', 'v2');
        $this->candidates->save(ApworldCandidate::submit('c-1', $update->gameId, 'hash', 'hash.apworld', 'hash.apworld', "x\n", 'Aaa', 'v2', ApworldCandidateOrigin::Manual, 'admin-1', new \DateTimeImmutable()));

        $report = $this->submitter()->submit([$update]);

        self::assertSame([], $this->bus->dispatched);
        self::assertSame(1, $report->skipped);
    }

    public function testNeverRetriesAVersionThatWasRejected(): void
    {
        // Story 38.6 AC 7: a broken release is not re-tested every night until a new one comes out.
        $update = $this->update('aaa', 'CrystalProject-v0.17.0');
        $rejected = ApworldCandidate::submit('c-1', $update->gameId, 'hash', 'hash.apworld', 'hash.apworld', "x\n", 'Aaa', 'CrystalProject-v0.17.0', ApworldCandidateOrigin::Auto, null, new \DateTimeImmutable());
        $rejected->reject('Fill.FillError', new \DateTimeImmutable());
        $this->candidates->save($rejected);

        $report = $this->submitter()->submit([$update]);

        self::assertSame([], $this->bus->dispatched);
        self::assertSame(1, $report->skipped);
    }

    public function testAnAmbiguousReleaseOpensAnIncidentAndQueuesNothing(): void
    {
        $update = $this->update('multi', 'v3', assets: ['world_a.apworld', 'world_b.apworld']);

        $report = $this->submitter()->submit([$update]);

        self::assertCount(1, $report->openedIncidentIds);
        $incident = $this->incidents->findById($report->openedIncidentIds[0]);
        self::assertSame(ApworldIncidentType::UpdateAmbiguous, $incident?->getType());
        self::assertSame('release:v3', $incident->getApworldHash());
        self::assertStringContainsString('world_a.apworld', $incident->getError());
        self::assertSame([], array_filter($this->bus->messages(), static fn (object $m): bool => $m instanceof SubmitAutoApworldUpdateJob));
    }

    public function testAnAmbiguousReleaseDoesNotUseUpTheCap(): void
    {
        $updates = [$this->update('multi', 'v3', assets: ['a.apworld', 'b.apworld']), $this->update('aaa', 'v2')];

        $report = $this->submitter(cap: 1)->submit($updates);

        self::assertSame(1, $report->queued);
    }

    public function testAGithubFailureSkipsThatGameOnly(): void
    {
        $updates = [$this->update('broken', 'v2'), $this->update('aaa', 'v2')];

        $report = $this->submitter()->submit($updates);

        self::assertSame(1, $report->queued);
        self::assertSame(1, $report->failed);
    }

    /**
     * @param list<string> $assets
     */
    private function update(string $repo, string $tag, array $assets = ['world.apworld']): ApworldUpdateAvailable
    {
        $game = Game::create(ucfirst($repo), $repo, 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/'.$repo, deployedVersion: 'v1');
        $this->games[$repo] = $game;
        $this->assetsByRepo[$repo] = [$tag, $assets];

        return new ApworldUpdateAvailable($game->getId(), $game->getName(), $tag, $assets[0], 'https://github.com/owner/'.$repo.'/releases/download/'.$tag.'/'.$assets[0]);
    }

    /** @var array<string, array{string, list<string>}> */
    private array $assetsByRepo = [];

    private function submitter(int $cap = 10): SubmitAvailableApworldUpdates
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturnCallback(function (string $id): ?Game {
            foreach ($this->games as $game) {
                if ($game->getId() === $id) {
                    return $game;
                }
            }

            return null;
        });

        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $repo = (string) preg_replace('#^https://api\.github\.com/repos/owner/([^/]+)/.*$#', '$1', $url);
            if ('broken' === $repo) {
                return new MockResponse('', ['error' => 'Recv failure: Connection was reset']);
            }
            [$tag, $assets] = $this->assetsByRepo[$repo];

            return new MockResponse((string) json_encode([[
                'tag_name' => $tag,
                'name' => $tag,
                'draft' => false,
                'prerelease' => false,
                'assets' => array_map(static fn (string $name): array => [
                    'name' => $name,
                    'browser_download_url' => 'https://github.com/owner/'.$repo.'/releases/download/'.$tag.'/'.$name,
                    'size' => 1024,
                ], $assets),
            ]]), ['response_headers' => ['x-ratelimit-remaining' => ['4000']]]);
        });

        $clock = new MockClock('2026-09-26 04:05:00+00:00');

        return new SubmitAvailableApworldUpdates(
            $repository,
            $this->candidates,
            new ApworldVersionChecker($client, new NullLogger(), 'ghp_test_token'),
            new RecordApworldIncident($this->incidents, $clock),
            $this->incidents,
            $this->bus,
            new NullLogger(),
            $cap,
        );
    }
}
