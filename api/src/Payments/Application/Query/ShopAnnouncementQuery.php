<?php

declare(strict_types=1);

namespace App\Payments\Application\Query;

use App\Payments\Domain\Entity\ShopAnnouncement;
use App\Payments\Domain\Repository\ShopAnnouncementRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The HelloAsso shop banner (story 41.14): members read it while it runs, the admin reads it whatever its state.
 */
final readonly class ShopAnnouncementQuery
{
    public function __construct(
        private ShopAnnouncementRepositoryInterface $announcements,
        private ClockInterface $clock,
    ) {
    }

    /** @return array{message: string, endsAt: string}|null */
    public function running(): ?array
    {
        $current = $this->announcements->current();
        if (!$current instanceof ShopAnnouncement || !$current->isRunning($this->clock->now())) {
            return null;
        }

        return ['message' => $current->getMessage(), 'endsAt' => $current->getEndsAt()->format(\DATE_ATOM)];
    }

    /** @return array{message: string, endsAt: string, running: bool}|null */
    public function admin(): ?array
    {
        $current = $this->announcements->current();
        if (!$current instanceof ShopAnnouncement) {
            return null;
        }

        return [
            'message' => $current->getMessage(),
            'endsAt' => $current->getEndsAt()->format(\DATE_ATOM),
            'running' => $current->isRunning($this->clock->now()),
        ];
    }
}
