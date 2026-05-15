<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\IgdbCandidate;
use App\CatalogSync\Application\IgdbEnrichmentService;
use App\GameSelection\Infrastructure\IgdbAuthException;
use App\GameSelection\Infrastructure\IgdbHttpClientInterface;
use App\GameSelection\Infrastructure\IgdbSearchException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class IgdbEnrichmentServiceTest extends TestCase
{
    public function testSearchReturnsMappedCandidates(): void
    {
        $client = $this->createMock(IgdbHttpClientInterface::class);
        $client->expects(self::once())
            ->method('searchGames')
            ->with('Hollow Knight', 3)
            ->willReturn([
                ['igdbId' => 1, 'name' => 'Hollow Knight', 'slug' => 'hollow-knight', 'summary' => 'A platformer.', 'coverUrl' => 'https://example.com/cover.jpg'],
                ['igdbId' => 2, 'name' => 'Hollow Knight: Silksong', 'slug' => 'silksong', 'summary' => null, 'coverUrl' => null],
            ]);

        $service = new IgdbEnrichmentService($client, new NullLogger());
        $results = $service->search('Hollow Knight');

        self::assertCount(2, $results);
        self::assertInstanceOf(IgdbCandidate::class, $results[0]);
        self::assertSame(1, $results[0]->igdbId);
        self::assertSame('Hollow Knight', $results[0]->name);
        self::assertSame('A platformer.', $results[0]->summary);
        self::assertSame('https://example.com/cover.jpg', $results[0]->coverUrl);
        self::assertSame(2, $results[1]->igdbId);
        self::assertNull($results[1]->summary);
        self::assertNull($results[1]->coverUrl);
    }

    public function testSearchLimitsQueryToThreeCandidates(): void
    {
        $client = $this->createMock(IgdbHttpClientInterface::class);
        $client->expects(self::once())
            ->method('searchGames')
            ->with(self::anything(), 3)
            ->willReturn([]);

        $service = new IgdbEnrichmentService($client, new NullLogger());
        $service->search('any');
    }

    public function testSearchReturnsEmptyArrayAndLogsWarningOnIgdbAuthException(): void
    {
        $client = $this->createStub(IgdbHttpClientInterface::class);
        $client->method('searchGames')->willThrowException(new IgdbAuthException('No credentials'));

        $logger = new IgdbEnrichmentSpyLogger();
        $service = new IgdbEnrichmentService($client, $logger);
        $result = $service->search('test');

        self::assertSame([], $result);
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('igdb', $logger->warnings[0]);
    }

    public function testSearchReturnsEmptyArrayAndLogsWarningOnIgdbSearchException(): void
    {
        $client = $this->createStub(IgdbHttpClientInterface::class);
        $client->method('searchGames')->willThrowException(new IgdbSearchException('Search failed'));

        $logger = new IgdbEnrichmentSpyLogger();
        $service = new IgdbEnrichmentService($client, $logger);
        $result = $service->search('test');

        self::assertSame([], $result);
        self::assertCount(1, $logger->warnings);
    }
}

final class IgdbEnrichmentSpyLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if ('warning' === $level) {
            $this->warnings[] = (string) $message;
        }
    }
}
