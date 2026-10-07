<?php

declare(strict_types=1);

namespace App\Payments\Application\Command;

use App\Payments\Domain\Entity\ShopAnnouncement;
use App\Payments\Domain\Repository\ShopAnnouncementRepositoryInterface;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Posts, rewrites or takes down the banner announcing a HelloAsso shop promotion (story 41.14).
 */
final readonly class ManageShopAnnouncement
{
    public function __construct(
        private ShopAnnouncementRepositoryInterface $announcements,
        private ClockInterface $clock,
    ) {
    }

    /** @throws ValidationException when the message is empty or too long, or the end is already past */
    public function post(string $message, ?\DateTimeImmutable $endsAt): void
    {
        if (null === $endsAt) {
            throw new ValidationException('Le bandeau a une date de fin.', [], 'shop_announcement_end_required');
        }
        $now = $this->clock->now();
        try {
            $current = $this->announcements->current();
            if ($current instanceof ShopAnnouncement) {
                $current->rewrite($message, $endsAt, $now);
            } else {
                $current = ShopAnnouncement::post($message, $endsAt, $now);
            }
        } catch (\DomainException $e) {
            throw new ValidationException(sprintf('Un message de 1 à %d caractères, une fin à venir.', ShopAnnouncement::MAX_MESSAGE), [], $e->getMessage());
        }
        $this->announcements->save($current);
    }

    public function takeDown(): void
    {
        $current = $this->announcements->current();
        if ($current instanceof ShopAnnouncement) {
            $this->announcements->remove($current);
        }
    }
}
