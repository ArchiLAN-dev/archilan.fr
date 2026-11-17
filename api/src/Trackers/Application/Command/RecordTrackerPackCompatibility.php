<?php

declare(strict_types=1);

namespace App\Trackers\Application\Command;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Trackers\Domain\Entity\TrackerPackCompatibility;
use App\Trackers\Domain\Repository\TrackerPackRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps what an admin's try of a pack variant found (story 54.8), replacing the previous try of that variant. The
 * report comes from the admin's browser, which ran the pack: it is bounded, not trusted beyond its shape.
 */
final readonly class RecordTrackerPackCompatibility
{
    public function __construct(
        private TrackerPackRepositoryInterface $packs,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws NotFoundException tracker_pack_version_not_found, tracker_pack_variant_not_found */
    public function record(string $versionId, TrackerPackCompatibilityReport $report, string $adminId): void
    {
        $version = $this->packs->findVersion($versionId);
        if (null === $version) {
            throw new NotFoundException('Cette version du pack n\'existe plus.', 'tracker_pack_version_not_found');
        }
        $variants = array_column($version->getVariants(), 'uid');
        if (!in_array($report->variantUid, $variants, true)) {
            throw new NotFoundException('Cette variante n\'existe pas dans cette version du pack.', 'tracker_pack_variant_not_found');
        }
        if (count($report->errors) > TrackerPackCompatibility::MAX_ERRORS || count($report->unsupportedApis) > TrackerPackCompatibility::MAX_UNSUPPORTED) {
            throw new ValidationException('Rapport trop long.', [], 'tracker_compatibility_too_long');
        }

        $now = $this->clock->now();
        $existing = $this->packs->findCompatibility($versionId, $report->variantUid);
        if (null === $existing) {
            $existing = TrackerPackCompatibility::report(
                $versionId,
                $report->variantUid,
                $report->status,
                $report->errors,
                $report->unsupportedApis,
                $report->engineVersion,
                $adminId,
                $now,
            );
        } else {
            $existing->replace($report->status, $report->errors, $report->unsupportedApis, $report->engineVersion, $adminId, $now);
        }
        $this->packs->saveCompatibility($existing);
        $this->logger->info('tracker_pack.compatibility_recorded', [
            'versionId' => $versionId,
            'variantUid' => $report->variantUid,
            'status' => $report->status->value,
            'adminId' => $adminId,
        ]);
    }
}
