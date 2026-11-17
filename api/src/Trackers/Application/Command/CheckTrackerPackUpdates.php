<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

use App\Shared\Application\Exception\ApplicationFailure;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Trackers\Application\Port\TrackerPackUpdateSourceInterface;
use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Repository\TrackerPackRepositoryInterface;
use App\Trackers\Domain\Service\TrackerPackVersions;
use App\Trackers\Domain\ValueObject\TrackerPackSource;
use App\Trackers\Domain\ValueObject\TrackerPackUpdate;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Looks for a newer version of packs (story 54.3), in the pack's `versions.json` and in the latest release of its
 * repository, and keeps the highest one found above the version served. Nothing is installed: a pack runs code in
 * the players' browsers, an admin applies the update.
 *
 * Each pack checked is saved on its own, so a pass cut short keeps what it did. GitHub's quota stops the nightly pass
 * cleanly; the next night resumes.
 */
final readonly class CheckTrackerPackUpdates
{
    private const string RATE_LIMITED = 'github_rate_limited';

    public function __construct(
        private TrackerPackRepositoryInterface $packs,
        private TrackerPackUpdateSourceInterface $sources,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws NotFoundException           tracker_pack_not_found
     * @throws ServiceUnavailableException github_rate_limited
     */
    public function checkOne(string $packId): void
    {
        $pack = $this->packs->find($packId);
        if (null === $pack) {
            throw new NotFoundException('Pack introuvable.', 'tracker_pack_not_found');
        }
        $rateLimited = $this->check($pack);
        $this->packs->flush();
        if (null !== $rateLimited) {
            throw $rateLimited;
        }
    }

    /** The nightly pass, over the packs offered to players. */
    public function checkAll(): void
    {
        $checked = 0;
        $found = 0;
        foreach ($this->packs->findEnabled() as $pack) {
            $rateLimited = $this->check($pack);
            $this->packs->flush();
            if (null !== $rateLimited) {
                $this->logger->warning('tracker_pack.update_check_stopped', ['reason' => self::RATE_LIMITED, 'checked' => $checked]);

                return;
            }
            ++$checked;
            if (null !== $pack->getAvailableUpdate()) {
                ++$found;
            }
        }
        $this->logger->info('tracker_pack.update_check_done', ['checked' => $checked, 'updatesAvailable' => $found]);
    }

    /** @return ServiceUnavailableException|null the quota failure that should stop the caller */
    private function check(TrackerPack $pack): ?ServiceUnavailableException
    {
        $now = $this->clock->now();
        $current = $this->packs->findVersion($pack->getCurrentVersionId())?->getPackageVersion();
        $candidates = [];
        $failures = [];

        $versionsUrl = $pack->getVersionsUrl();
        if (null !== $versionsUrl) {
            try {
                $candidates[] = $this->sources->fromVersionsFile($versionsUrl);
            } catch (ApplicationFailure $e) {
                $failures[] = $e;
            }
        }
        try {
            $candidates[] = $this->sources->fromLatestRelease(TrackerPackSource::parse($pack->getSourceUrl()));
        } catch (ApplicationFailure $e) {
            $failures[] = $e;
        } catch (\DomainException) {
            // A source link stored by 54.1 always parses; nothing to look at otherwise.
        }

        foreach ($failures as $failure) {
            $this->logger->warning('tracker_pack.update_check_failed', ['packId' => $pack->getId(), 'code' => $failure->errorCode()]);
        }
        $rateLimited = array_find($failures, static fn (ApplicationFailure $f): bool => self::RATE_LIMITED === $f->errorCode());

        if ([] === $candidates && [] !== $failures) {
            $pack->recordCheckFailure($failures[0]->errorCode(), $now);
        } else {
            $update = TrackerPackVersions::pickUpdate($current, ...$candidates);
            $pack->recordCheck($update, $now);
            $this->logUpdate($pack, $update);
        }

        return $rateLimited instanceof ServiceUnavailableException ? $rateLimited : null;
    }

    private function logUpdate(TrackerPack $pack, ?TrackerPackUpdate $update): void
    {
        if (null !== $update) {
            $this->logger->info('tracker_pack.update_available', ['packId' => $pack->getId(), 'packageVersion' => $update->packageVersion]);
        }
    }
}
