<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\Command\CheckApworldUpdatesService;
use App\CatalogSync\Application\Command\SubmitAvailableApworldUpdates;
use App\CatalogSync\Application\Handler\CheckApworldUpdatesHandler;
use App\CatalogSync\Application\Message\CheckApworldUpdatesMessage;
use App\CatalogSync\Application\Message\SubmitAutoApworldUpdateJob;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
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
 * Story 38.6: the nightly check hands what it found to the automatic update.
 */
final class CheckApworldUpdatesHandlerTest extends TestCase
{
    public function testTheNightlyCheckQueuesTheUpdatesItFound(): void
    {
        $behind = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $behind->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/crystal', deployedVersion: 'CrystalProject-v0.17.0');
        $upToDate = Game::create('Up To Date', 'up-to-date', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $upToDate->updateCatalogueMetadata(sourceUrl: 'https://github.com/owner/uptodate', deployedVersion: '2.0.0');

        $tags = ['crystal' => 'CrystalProject-v0.18.2', 'uptodate' => 'v2.0.0'];
        $client = new MockHttpClient(static function (string $method, string $url) use ($tags): MockResponse {
            $repo = (string) preg_replace('#^https://api\.github\.com/repos/owner/([^/]+)/.*$#', '$1', $url);

            return new MockResponse((string) json_encode([[
                'tag_name' => $tags[$repo],
                'name' => $tags[$repo],
                'published_at' => '2026-09-11T22:15:28Z',
                'html_url' => 'https://github.com/owner/'.$repo.'/releases/tag/'.$tags[$repo],
                'draft' => false,
                'prerelease' => false,
                'assets' => [['name' => 'world.apworld', 'browser_download_url' => 'https://github.com/owner/'.$repo.'/releases/download/'.$tags[$repo].'/world.apworld', 'size' => 1]],
            ]]), ['response_headers' => ['x-ratelimit-remaining' => ['4000']]]);
        });
        $checker = new ApworldVersionChecker($client, new NullLogger(), 'ghp_test_token');

        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findAllSortedByName')->willReturn([$behind, $upToDate]);
        $games->method('findById')->willReturnCallback(static fn (string $id): ?Game => $id === $behind->getId() ? $behind : ($id === $upToDate->getId() ? $upToDate : null));
        $incidents = new InMemoryApworldIncidentRepository();
        $bus = new SpyMessageBus($incidents);
        $clock = new MockClock();

        $handler = new CheckApworldUpdatesHandler(
            new CheckApworldUpdatesService($checker, $games, new NullLogger(), $clock),
            new SubmitAvailableApworldUpdates($games, new InMemoryApworldCandidateRepository(), $checker, new RecordApworldIncident($incidents, $clock), $incidents, $bus, new NullLogger(), 10),
            new NullLogger(),
        );

        $handler(new CheckApworldUpdatesMessage());

        self::assertEquals(
            [new SubmitAutoApworldUpdateJob($behind->getId(), 'CrystalProject-v0.18.2', 'https://github.com/owner/crystal/releases/download/CrystalProject-v0.18.2/world.apworld', 'world.apworld')],
            $bus->messages(),
        );
    }
}
