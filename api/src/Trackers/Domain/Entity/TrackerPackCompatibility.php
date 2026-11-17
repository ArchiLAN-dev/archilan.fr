<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Entity;

use App\Trackers\Domain\Enum\TrackerPackCompatibilityStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * The last time an admin tried one variant of a pack version on the site (story 54.8): whether it loaded, what the
 * pack reported, and which PopTracker functions it needed that the site lacks. A new try replaces it. The engine
 * version tells which reports predate a change of the site's engine.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tracker_pack_compatibility')]
final class TrackerPackCompatibility
{
    public const int MAX_ERRORS = 50;
    public const int MAX_UNSUPPORTED = 100;
    public const int MAX_MESSAGE = 500;
    public const int MAX_NAME = 120;

    /**
     * @param list<array{kind: string, message: string, file: ?string, line: ?int}> $errors
     * @param list<string>                                                          $unsupportedApis
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'version_id', type: 'string', length: 32)]
        private string $versionId,
        #[ORM\Id]
        #[ORM\Column(name: 'variant_uid', type: 'string', length: 120)]
        private string $variantUid,
        #[ORM\Column(type: 'string', length: 16, enumType: TrackerPackCompatibilityStatus::class)]
        private TrackerPackCompatibilityStatus $status,
        #[ORM\Column(type: 'json')]
        private array $errors,
        #[ORM\Column(name: 'unsupported_apis', type: 'json')]
        private array $unsupportedApis,
        #[ORM\Column(name: 'engine_version', type: 'integer')]
        private int $engineVersion,
        #[ORM\Column(name: 'reported_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $reportedAt,
        #[ORM\Column(name: 'reported_by', type: 'string', length: 64)]
        private string $reportedBy,
    ) {
    }

    /**
     * @param list<array{kind: string, message: string, file: ?string, line: ?int}> $errors
     * @param list<string>                                                          $unsupportedApis
     */
    public static function report(
        string $versionId,
        string $variantUid,
        TrackerPackCompatibilityStatus $status,
        array $errors,
        array $unsupportedApis,
        int $engineVersion,
        string $adminId,
        \DateTimeImmutable $now,
    ): self {
        return new self($versionId, $variantUid, $status, $errors, $unsupportedApis, $engineVersion, $now, $adminId);
    }

    /**
     * @param list<array{kind: string, message: string, file: ?string, line: ?int}> $errors
     * @param list<string>                                                          $unsupportedApis
     */
    public function replace(
        TrackerPackCompatibilityStatus $status,
        array $errors,
        array $unsupportedApis,
        int $engineVersion,
        string $adminId,
        \DateTimeImmutable $now,
    ): void {
        $this->status = $status;
        $this->errors = $errors;
        $this->unsupportedApis = $unsupportedApis;
        $this->engineVersion = $engineVersion;
        $this->reportedBy = $adminId;
        $this->reportedAt = $now;
    }

    public function getVersionId(): string
    {
        return $this->versionId;
    }

    public function getVariantUid(): string
    {
        return $this->variantUid;
    }

    public function getStatus(): TrackerPackCompatibilityStatus
    {
        return $this->status;
    }

    /** @return list<array{kind: string, message: string, file: ?string, line: ?int}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function getUnsupportedApis(): array
    {
        return $this->unsupportedApis;
    }

    public function getEngineVersion(): int
    {
        return $this->engineVersion;
    }
}
