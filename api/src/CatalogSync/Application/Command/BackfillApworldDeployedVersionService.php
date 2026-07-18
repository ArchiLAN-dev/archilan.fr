<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

use App\CatalogSync\Application\Exception\GithubRateLimitException;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * One-shot backfill of GameCatalogSync::apworldDeployedVersion.
 *
 * Targets GitHub-tracked games that have an uploaded apworld (hash set) but no recorded
 * deployed version (configured by direct upload, or added before version tracking). It
 * recovers the version by matching the stored sha256 against the source repo's release
 * assets. Byte-exact match => false negatives only, never false positives; unmatched games
 * are reported so an admin can set them by hand. Idempotent: resolved games drop out of the
 * target set on the next run.
 */
final readonly class BackfillApworldDeployedVersionService
{
    public function __construct(
        private ApworldVersionChecker $checker,
        private GameRepositoryInterface $gameRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function backfill(bool $dryRun): ApworldDeployedVersionBackfillReport
    {
        $targets = array_values(array_filter(
            $this->gameRepository->findAllSortedByName(),
            static function (Game $game): bool {
                $sync = $game->getCatalogSync();

                return null !== $sync
                    && str_starts_with($sync->getApworldSourceUrl() ?? '', 'https://github.com/')
                    && null !== $game->getApworldHash()
                    && null === $sync->getApworldDeployedVersion();
            },
        ));

        // Group by exact source URL: the ?q= filter is part of the URL, so identical URLs
        // share one release-asset hash map - the repo's assets are downloaded only once.
        /** @var array<string, list<Game>> $groups */
        $groups = [];
        foreach ($targets as $game) {
            $groups[$game->getApworldSourceUrl() ?? ''][] = $game;
        }

        $matched = 0;
        $unmatched = 0;
        $unmatchedGames = [];
        $rateLimitHit = false;

        foreach ($groups as $games) {
            try {
                $hashToTag = $this->checker->mapApworldAssetHashesByTag($games[0]);
            } catch (GithubRateLimitException $e) {
                $rateLimitHit = true;
                $this->logger->warning('catalog_sync.apworld_backfill_rate_limit', ['message' => $e->getMessage()]);
                break;
            }

            foreach ($games as $game) {
                $hash = strtolower($game->getApworldHash() ?? '');
                $tag = $hashToTag[$hash] ?? null;

                if (null !== $tag) {
                    if (!$dryRun) {
                        $game->getCatalogSync()?->recordApworldDeployment($tag);
                    }
                    ++$matched;
                    $this->logger->info('catalog_sync.apworld_backfill_matched', [
                        'game' => $game->getName(),
                        'tag' => $tag,
                        'dryRun' => $dryRun,
                    ]);
                } else {
                    ++$unmatched;
                    $unmatchedGames[] = $game->getName();
                    $this->logger->info('catalog_sync.apworld_backfill_unmatched', [
                        'game' => $game->getName(),
                        'hash' => substr($hash, 0, 12),
                    ]);
                }
            }
        }

        if (!$dryRun) {
            $this->gameRepository->flush();
        }

        return new ApworldDeployedVersionBackfillReport(
            $matched,
            $unmatched,
            \count($targets),
            $unmatchedGames,
            $rateLimitHit,
        );
    }
}
