<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use App\Community\Domain\Enum\NotificationChannel;
use Doctrine\ORM\Mapping as ORM;

/**
 * A member's choice for one type of notification (story 43.11b). No row means the type's default. Story 40.6: whether
 * it also goes to the member's Discord direct messages, null for the type's default.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_notification_preference')]
#[ORM\UniqueConstraint(name: 'uniq_community_notification_preference', columns: ['user_id', 'type'])]
final class NotificationPreference
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(type: 'string', length: 32)]
        private string $type,
        #[ORM\Column(type: 'string', length: 16, enumType: NotificationChannel::class)]
        private NotificationChannel $channel,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(type: 'boolean', nullable: true)]
        private ?bool $discord = null,
    ) {
    }

    public static function create(string $userId, string $type, NotificationChannel $channel, \DateTimeImmutable $now, ?bool $discord = null): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $type, $channel, $now, $discord);
    }

    public function chooseDiscord(bool $discord, \DateTimeImmutable $now): void
    {
        $this->discord = $discord;
        $this->updatedAt = $now;
    }

    public function getDiscord(): ?bool
    {
        return $this->discord;
    }

    public function choose(NotificationChannel $channel, \DateTimeImmutable $now): void
    {
        $this->channel = $channel;
        $this->updatedAt = $now;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
