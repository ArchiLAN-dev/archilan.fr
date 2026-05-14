<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation;

use App\CatalogSync\Application\ApworldVersionChecker;
use App\CatalogSync\Application\CatalogSyncService;
use App\CatalogSync\Domain\CatalogEntry;
use App\GameSelection\Domain\ArchipelagoGame;
use App\GameSelection\Domain\IgnoredCatalogEntry;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CatalogSyncController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CatalogSyncService $catalogSyncService,
        private ApworldVersionChecker $apworldVersionChecker,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/admin/catalog-sync', name: 'api_catalog_sync', methods: ['GET'])]
    public function catalogSync(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $force = $request->query->getBoolean('force');

        try {
            if ($force) {
                $this->catalogSyncService->invalidateCache();
            }

            $sheetEntries = $this->catalogSyncService->fetchSheet();
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse(
                'sheet_unavailable',
                'Le catalogue Google Sheets est injoignable.',
                503,
            );
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

        $trackedGames = array_values(array_filter(
            $games,
            static fn (ArchipelagoGame $g): bool => ArchipelagoGame::UPDATE_STATUS_NOT_TRACKED !== $g->computeApworldUpdateStatus(),
        ));

        return new JsonResponse([
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
                $trackedGames,
            ),
        ]);
    }

    #[Route('/api/v1/admin/catalog-sync/ignored', name: 'api_catalog_sync_ignore', methods: ['POST'])]
    public function ignore(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true) ?? [];
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';

        if ('' === $name) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le nom est requis.', 422);
        }

        $existing = $this->entityManager->find(IgnoredCatalogEntry::class, $name);

        if (null === $existing) {
            $entry = new IgnoredCatalogEntry($name, new \DateTimeImmutable());
            $this->entityManager->persist($entry);
            $this->entityManager->flush();
        }

        return new JsonResponse(['name' => $name], 201);
    }

    #[Route('/api/v1/admin/catalog-sync/ignored/{name}', name: 'api_catalog_sync_unignore', methods: ['DELETE'])]
    public function unignore(Request $request, string $name): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $entry = $this->entityManager->find(IgnoredCatalogEntry::class, $name);

        if (null === $entry) {
            return new JsonResponse(null, 404);
        }

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }
}
