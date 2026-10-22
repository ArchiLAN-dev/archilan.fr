<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Query;

use App\CatalogSync\Domain\Entity\ApworldUpdateCheckRun;

/**
 * Story 38.18: where a pass checking the apworlds for updates stands, as the catalogue page shows it.
 */
final readonly class ApworldUpdateCheckRunView
{
    public function __construct(
        public string $id,
        public string $status,
        public string $trigger,
        public string $queuedAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $total,
        public int $checked,
        public int $failed,
        public bool $rateLimitHit,
        public int $updatesAvailable,
        public ?string $error,
    ) {
    }

    public static function of(ApworldUpdateCheckRun $run): self
    {
        return new self(
            $run->getId(),
            $run->getStatus(),
            $run->getTrigger(),
            $run->getQueuedAt()->format(\DATE_ATOM),
            $run->getStartedAt()?->format(\DATE_ATOM),
            $run->getFinishedAt()?->format(\DATE_ATOM),
            $run->getTotal(),
            $run->getChecked(),
            $run->getFailed(),
            $run->isRateLimitHit(),
            $run->getUpdatesAvailable(),
            $run->getError(),
        );
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'queuedAt' => $this->queuedAt,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'total' => $this->total,
            'checked' => $this->checked,
            'failed' => $this->failed,
            'rateLimitHit' => $this->rateLimitHit,
            'updatesAvailable' => $this->updatesAvailable,
            'error' => $this->error,
        ];
    }
}
