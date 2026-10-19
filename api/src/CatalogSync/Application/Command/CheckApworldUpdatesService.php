<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

use App\CatalogSync\Application\Exception\GithubRateLimitException;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\CatalogSync\Application\Support\ApworldVersionInfo;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

final readonly class CheckApworldUpdatesService
{
    /**
     * Games checked between two saves (story 38.18). A pass cut short keeps what it did: the versions read and,
     * above all, the date of last check that puts the games it reached at the back of the next pass's queue.
     */
    public const int BATCH_SIZE = 25;

    public function __construct(
        private ApworldVersionChecker $checker,
        private GameRepositoryInterface $gameRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Checks every game tracked on GitHub (story 14.5), daily since story 38.5.
     *
     * Least recently checked first, never-checked before all: when the GitHub rate limit stops a
     * pass, the next one resumes with the games this one did not reach, instead of starting the
     * alphabet over and starving its end forever.
     *
     * Saved every BATCH_SIZE games, not only at the end (story 38.18): a pass interrupted halfway (a timeout,
     * a worker restart) no longer loses all its work and sends the next one back to the same games.
     * `$onProgress` is told the total, then the report so far after each saved batch.
     *
     * @param (\Closure(int, ApworldUpdateCheckReport): void)|null $onProgress
     */
    public function checkAll(?\Closure $onProgress = null): ApworldUpdateCheckReport
    {
        $games = array_values(array_filter(
            $this->gameRepository->findAllSortedByName(),
            static fn (Game $g): bool => str_starts_with($g->getCatalogSync()?->getApworldSourceUrl() ?? '', 'https://github.com/'),
        ));
        // Stable sort: games checked at the same moment keep their alphabetical order. On the date of the
        // last check - not the release date, which the check only reads (story 38.5 review).
        usort($games, static fn (Game $a, Game $b): int => ($a->getApworldLastCheckedAt()?->getTimestamp() ?? \PHP_INT_MIN)
            <=> ($b->getApworldLastCheckedAt()?->getTimestamp() ?? \PHP_INT_MIN));
        $now = $this->clock->now();

        $checked = 0;
        $failed = 0;
        $rateLimitHit = false;
        $updatesAvailable = [];
        $total = \count($games);
        if (null !== $onProgress) {
            $onProgress($total, new ApworldUpdateCheckReport(0, false));
        }

        foreach ($games as $index => $game) {
            // Tried, whatever the outcome: a repository that always fails must not hold the head of the queue.
            $game->markApworldChecked($now);
            try {
                $info = $this->checker->check($game);

                if (null !== $info) {
                    ++$checked;
                    $this->report($game, $info, $updatesAvailable);
                }
            } catch (GithubRateLimitException $e) {
                // The check that spent the quota is recorded on its game: report it, then stop.
                if (null !== $e->completedCheck) {
                    ++$checked;
                    $this->report($game, $e->completedCheck, $updatesAvailable);
                }
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

            if (0 === ($index + 1) % self::BATCH_SIZE) {
                $this->gameRepository->flush();
                if (null !== $onProgress) {
                    $onProgress($total, new ApworldUpdateCheckReport($checked, $rateLimitHit, $updatesAvailable, $failed));
                }
            }
        }

        $this->gameRepository->flush();

        return new ApworldUpdateCheckReport($checked, $rateLimitHit, $updatesAvailable, $failed);
    }

    /**
     * @param list<ApworldUpdateAvailable> $updatesAvailable
     */
    private function report(Game $game, ApworldVersionInfo $info, array &$updatesAvailable): void
    {
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
}
