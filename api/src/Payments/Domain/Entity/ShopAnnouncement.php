<?php

declare(strict_types=1);

namespace App\Payments\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The banner announcing a promotion on the HelloAsso shop (story 41.14). The prices of those items live in HelloAsso,
 * so the site cannot discount them itself: an admin sets the discount there and writes here what the members read,
 * until when. A single line - one banner at a time.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_announcement')]
final class ShopAnnouncement
{
    public const string HELLOASSO_SHOP = 'helloasso-shop';
    public const int MAX_MESSAGE = 200;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 200)]
        private string $message,
        #[ORM\Column(name: 'ends_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $endsAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function post(string $message, \DateTimeImmutable $endsAt, \DateTimeImmutable $now): self
    {
        $announcement = new self(self::HELLOASSO_SHOP, '', $endsAt, $now);
        $announcement->rewrite($message, $endsAt, $now);

        return $announcement;
    }

    public function rewrite(string $message, \DateTimeImmutable $endsAt, \DateTimeImmutable $now): void
    {
        $message = trim($message);
        if ('' === $message || mb_strlen($message) > self::MAX_MESSAGE) {
            throw new \DomainException('shop_announcement_message_invalid');
        }
        if ($endsAt <= $now) {
            throw new \DomainException('shop_announcement_end_invalid');
        }
        $this->message = $message;
        $this->endsAt = $endsAt;
        $this->updatedAt = $now;
    }

    public function isRunning(\DateTimeImmutable $now): bool
    {
        return $now < $this->endsAt;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }
}
