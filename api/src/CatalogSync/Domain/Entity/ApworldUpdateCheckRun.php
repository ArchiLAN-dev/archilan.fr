<?php

declare(strict_types=1);

namespace App\CatalogSync\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One pass checking the GitHub-tracked apworlds for a newer release (story 38.18): queued by the catalogue
 * page's button or started by the nightly run, then followed by the worker until it is done or failed.
 *
 * `active_slot` is the guard against two passes at once: it holds the same value while a pass is queued or
 * running and is cleared when it ends, and a unique constraint lets only one row hold it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'apworld_update_check_run')]
#[ORM\UniqueConstraint(name: 'uniq_apworld_update_check_run_active', columns: ['active_slot'])]
#[ORM\Index(name: 'idx_apworld_update_check_run_queued_at', columns: ['queued_at'])]
final class ApworldUpdateCheckRun
{
    public const string TRIGGER_BUTTON = 'button';
    public const string TRIGGER_NIGHTLY = 'nightly';

    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_DONE = 'done';
    public const string STATUS_FAILED = 'failed';

    public const string ACTIVE = 'active';

    /** A queued pass the worker never picked up in this time was lost (worker down, message dropped). */
    private const int QUEUED_STALE_SECONDS = 30 * 60;

    /** A pass reports its progress at least every batch; one silent this long died with its worker. */
    private const int RUNNING_STALE_SECONDS = 60 * 60;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'active_slot', type: 'string', length: 8, nullable: true)]
    private ?string $activeSlot = self::ACTIVE;

    #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'progressed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $progressedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $total = null;

    #[ORM\Column(type: 'integer')]
    private int $checked = 0;

    #[ORM\Column(type: 'integer')]
    private int $failed = 0;

    #[ORM\Column(name: 'rate_limit_hit', type: 'boolean')]
    private bool $rateLimitHit = false;

    #[ORM\Column(name: 'updates_available', type: 'integer')]
    private int $updatesAvailable = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'trigger_source', type: 'string', length: 16)]
        private string $trigger,
        #[ORM\Column(name: 'queued_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $queuedAt,
    ) {
    }

    public static function queue(string $id, string $trigger, \DateTimeImmutable $now): self
    {
        if (!\in_array($trigger, [self::TRIGGER_BUTTON, self::TRIGGER_NIGHTLY], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown trigger "%s".', $trigger));
        }

        return new self($id, $trigger, $now);
    }

    public function start(\DateTimeImmutable $now): void
    {
        if (self::STATUS_QUEUED !== $this->status) {
            throw new \DomainException('Only a queued pass can start.');
        }
        $this->status = self::STATUS_RUNNING;
        $this->startedAt = $now;
        $this->progressedAt = $now;
    }

    public function progress(int $total, int $checked, int $failed, int $updatesAvailable, bool $rateLimitHit, \DateTimeImmutable $now): void
    {
        $this->total = $total;
        $this->checked = $checked;
        $this->failed = $failed;
        $this->updatesAvailable = $updatesAvailable;
        $this->rateLimitHit = $rateLimitHit;
        $this->progressedAt = $now;
    }

    public function finish(int $checked, int $failed, int $updatesAvailable, bool $rateLimitHit, \DateTimeImmutable $now): void
    {
        $this->checked = $checked;
        $this->failed = $failed;
        $this->updatesAvailable = $updatesAvailable;
        $this->rateLimitHit = $rateLimitHit;
        $this->end(self::STATUS_DONE, $now);
    }

    public function fail(string $error, \DateTimeImmutable $now): void
    {
        $this->error = mb_substr($error, 0, 2000);
        $this->end(self::STATUS_FAILED, $now);
    }

    public function isActive(): bool
    {
        return self::STATUS_QUEUED === $this->status || self::STATUS_RUNNING === $this->status;
    }

    /** An active pass nothing has moved for too long: its worker is gone, it will never end on its own. */
    public function isStale(\DateTimeImmutable $now): bool
    {
        return match ($this->status) {
            self::STATUS_QUEUED => $now->getTimestamp() - $this->queuedAt->getTimestamp() > self::QUEUED_STALE_SECONDS,
            self::STATUS_RUNNING => $now->getTimestamp() - ($this->progressedAt ?? $this->queuedAt)->getTimestamp() > self::RUNNING_STALE_SECONDS,
            default => false,
        };
    }

    private function end(string $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->activeSlot = null;
        $this->finishedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getQueuedAt(): \DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function getChecked(): int
    {
        return $this->checked;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function isRateLimitHit(): bool
    {
        return $this->rateLimitHit;
    }

    public function getUpdatesAvailable(): int
    {
        return $this->updatesAvailable;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
