<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Domain\GameRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class CheckApworldUpdatesService
{
    public function __construct(
        private ApworldVersionChecker $checker,
        private GameRepositoryInterface $gameRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{checked: int, rateLimitHit: bool}
     */
    public function checkAll(): array
    {
        $allGames = $this->gameRepository->findAllSortedByName();
        $games = array_values(array_filter(
            $allGames,
            static fn (\App\GameSelection\Domain\Game $g): bool => str_starts_with($g->getCatalogSync()?->getApworldSourceUrl() ?? '', 'https://github.com/'),
        ));

        $checked = 0;
        $rateLimitHit = false;

        foreach ($games as $game) {
            try {
                $info = $this->checker->check($game);

                if (null !== $info) {
                    ++$checked;
                    $this->logger->info('catalog_sync.apworld_checked', [
                        'game' => $game->getName(),
                        'latestTag' => $info->latestTag,
                        'updateStatus' => $info->updateStatus,
                    ]);
                }
            } catch (GithubRateLimitException $e) {
                $rateLimitHit = true;
                $this->logger->warning('catalog_sync.rate_limit_hit', ['message' => $e->getMessage()]);
                break;
            }
        }

        $this->gameRepository->flush();

        return ['checked' => $checked, 'rateLimitHit' => $rateLimitHit];
    }
}
