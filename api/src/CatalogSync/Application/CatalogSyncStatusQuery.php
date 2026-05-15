<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\CatalogSync\Domain\CatalogEntry;
use App\GameSelection\Domain\ArchipelagoGame;
use App\GameSelection\Domain\IgnoredCatalogEntry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogSyncStatusQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CatalogSyncService $catalogSyncService,
        private ApworldVersionChecker $apworldVersionChecker,
    ) {
    }

    /**
     * Returns null when the Google Sheets catalog is unreachable.
     *
     * @return array{
     *     cachedAt: string|null,
     *     googleApiAvailable: bool,
     *     githubChecksAvailable: bool,
     *     newGames: list<array<string, mixed>>,
     *     ignoredGames: list<array<string, mixed>>,
     *     stabilityChanged: list<array<string, mixed>>,
     *     removedFromSheet: list<array<string, mixed>>,
     *     apworldUpdates: list<array<string, mixed>>,
     * }|null
     */
    public function fetch(bool $force): ?array
    {
        try {
            if ($force) {
                $this->catalogSyncService->invalidateCache();
            }
            $sheetEntries = $this->catalogSyncService->fetchSheet();
        } catch (\Throwable) {
            return null;
        }

        $cachedAt = $this->catalogSyncService->getCachedAt();

        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g', 'cs')
            ->from(ArchipelagoGame::class, 'g')
            ->leftJoin('g.catalogSync', 'cs')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<IgnoredCatalogEntry> $ignoredEntries */
        $ignoredEntries = $this->entityManager->getRepository(IgnoredCatalogEntry::class)->findAll();
        $ignoredNames = array_flip(array_map(static fn (IgnoredCatalogEntry $e): string => $e->getName(), $ignoredEntries));

        $diff = $this->catalogSyncService->computeDiff($sheetEntries, $games);

        $filteredNewGames = array_values(array_filter(
            $diff['newGames'],
            static fn (CatalogEntry $e): bool => !isset($ignoredNames[$e->name]),
        ));

        return [
            'cachedAt' => $cachedAt?->format(\DateTimeInterface::ATOM),
            'googleApiAvailable' => $this->catalogSyncService->isGoogleApiAvailable(),
            'githubChecksAvailable' => $this->apworldVersionChecker->isAvailable(),
            'newGames' => array_map(
                static fn (CatalogEntry $e): array => [
                    'name' => $e->name,
                    'availability' => $e->availability,
                    'bundledWithAp' => $e->bundledWithAp,
                    'adultContent' => $e->adultContent,
                    'links' => $e->links,
                ],
                $filteredNewGames,
            ),
            'ignoredGames' => array_map(
                static fn (IgnoredCatalogEntry $e): array => [
                    'name' => $e->getName(),
                    'ignoredAt' => $e->getIgnoredAt()->format(\DateTimeInterface::ATOM),
                ],
                $ignoredEntries,
            ),
            'stabilityChanged' => array_map(
                static fn (array $item): array => [
                    'gameId' => $item['game']->getId(),
                    'gameName' => $item['game']->getName(),
                    'currentAvailability' => $item['game']->getAvailability(),
                    'newAvailability' => $item['entry']->availability,
                    'availabilityLocked' => $item['game']->isAvailabilityLocked(),
                ],
                $diff['stabilityChanged'],
            ),
            'removedFromSheet' => array_map(
                static fn (ArchipelagoGame $g): array => [
                    'gameId' => $g->getId(),
                    'gameName' => $g->getName(),
                ],
                $diff['removedFromSheet'],
            ),
            'apworldUpdates' => array_map(
                static fn (ArchipelagoGame $g): array => [
                    'gameId' => $g->getId(),
                    'gameName' => $g->getName(),
                    'deployedVersion' => $g->getApworldDeployedVersion(),
                    'latestVersion' => $g->getApworldLatestVersion(),
                    'releaseUrl' => $g->getApworldReleaseUrl(),
                    'publishedAt' => $g->getApworldCheckedAt()?->format(\DateTimeInterface::ATOM),
                    'updateStatus' => $g->computeApworldUpdateStatus(),
                ],
                $games,
            ),
        ];
    }
}
