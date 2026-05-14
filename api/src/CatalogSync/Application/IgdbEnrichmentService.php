<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Infrastructure\IgdbHttpClientInterface;
use Psr\Log\LoggerInterface;

final readonly class IgdbEnrichmentService
{
    public function __construct(
        private IgdbHttpClientInterface $igdbHttpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<IgdbCandidate>
     */
    public function search(string $gameName): array
    {
        try {
            $results = $this->igdbHttpClient->searchGames($gameName, 3);
        } catch (\Throwable) {
            $this->logger->warning('igdb_enrichment.search_unavailable: IGDB returned an error, returning empty candidate list');

            return [];
        }

        return array_map(
            static fn (array $r): IgdbCandidate => new IgdbCandidate(
                igdbId: $r['igdbId'],
                name: $r['name'],
                summary: $r['summary'],
                coverUrl: $r['coverUrl'],
            ),
            $results,
        );
    }
}
