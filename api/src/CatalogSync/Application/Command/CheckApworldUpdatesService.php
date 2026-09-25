<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

use App\CatalogSync\Application\Exception\GithubRateLimitException;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

final readonly class CheckApworldUpdatesService
{
    public function __construct(
        private ApworldVersionChecker $checker,
        private GameRepositoryInterface $gameRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Checks every game tracked on GitHub (story 14.5), daily since story 38.5.
     *
     * Least recently checked first, never-checked before all: when the GitHub rate limit stops a
     * pass, the next one resumes with the games this one did not reach, instead of starting the
     * alphabet over and starving its end forever.
     */
    public function checkAll(): ApworldUpdateCheckReport
    {
        $games = array_values(array_filter(
            $this->gameRepository->findAllSortedByName(),
            static fn (Game $g): bool => str_starts_with($g->getCatalogSync()?->getApworldSourceUrl() ?? '', 'https://github.com/'),
        ));
        // Stable sort: games checked at the same moment keep their alphabetical order.
        usort($games, static fn (Game $a, Game $b): int => ($a->getApworldCheckedAt()?->getTimestamp() ?? \PHP_INT_MIN)
            <=> ($b->getApworldCheckedAt()?->getTimestamp() ?? \PHP_INT_MIN));

        $checked = 0;
        $failed = 0;
        $rateLimitHit = false;
        $updatesAvailable = [];

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
                    if (Game::UPDATE_STATUS_UPDATE_AVAILABLE === $info->updateStatus) {
                        $updatesAvailable[] = new ApworldUpdateAvailable(
                            $game->getId(),
                            $game->getName(),
                            $info->latestTag,
                            $info->assetName,
                            $info->assetDownloadUrl,
                        );
                    }
                }
            } catch (GithubRateLimitException $e) {
                $rateLimitHit = true;
                $this->logger->warning('catalog_sync.rate_limit_hit', ['message' => $e->getMessage()]);
                break;
            } catch (HttpClientExceptionInterface $e) {
                // One unreachable or malformed repository must not cost the whole nightly pass: found
                // on the first real run, where a reset connection aborted everything before the flush.
                ++$failed;
                $this->logger->warning('catalog_sync.apworld_check_failed', [
                    'game' => $game->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->gameRepository->flush();

        return new ApworldUpdateCheckReport($checked, $rateLimitHit, $updatesAvailable, $failed);
    }
}
