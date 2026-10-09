<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Application\Support\PushMessageFactory;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Entity\NotificationPreference;
use App\Community\Domain\Enum\NotificationChannel;
use App\Community\Domain\Repository\NotificationPreferenceRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Where each type of notification reaches a member (story 43.11b). Only the types listed here can be chosen; every
 * other type keeps the fixed rule of story 40.2 - pushed if `PushMessageFactory` has a text for it, bell otherwise.
 */
final readonly class NotificationPreferenceService
{
    /**
     * The configurable types and their default, in the order the settings show them.
     *
     * @var array<string, NotificationChannel>
     */
    private const array CONFIGURABLE = [
        Notification::TYPE_FRIEND_ACTIVITY => NotificationChannel::Bell,
        'run_invitation' => NotificationChannel::BellAndPush,
        'slot_unblocked' => NotificationChannel::BellAndPush,
    ];

    public function __construct(
        private NotificationPreferenceRepositoryInterface $preferences,
        private ClockInterface $clock,
    ) {
    }

    public function channelFor(string $userId, string $type): NotificationChannel
    {
        $default = self::CONFIGURABLE[$type] ?? null;
        if (null === $default) {
            return PushMessageFactory::isPushable($type) ? NotificationChannel::BellAndPush : NotificationChannel::Bell;
        }

        return $this->preferences->find($userId, $type)?->getChannel() ?? $default;
    }

    /**
     * @return list<array{type: string, channel: string}>
     */
    public function forUser(string $userId): array
    {
        $chosen = [];
        foreach ($this->preferences->forUser($userId) as $preference) {
            $chosen[$preference->getType()] = $preference->getChannel();
        }

        $list = [];
        foreach (self::CONFIGURABLE as $type => $default) {
            $list[] = ['type' => $type, 'channel' => ($chosen[$type] ?? $default)->value];
        }

        return $list;
    }

    /**
     * The member's choice for one type; the settings as they now stand, or null for a type or a channel that does
     * not exist.
     *
     * @return list<array{type: string, channel: string}>|null
     */
    public function choose(string $userId, string $type, string $channel): ?array
    {
        $chosen = NotificationChannel::tryFrom($channel);
        if (null === $chosen || !array_key_exists($type, self::CONFIGURABLE)) {
            return null;
        }

        $now = $this->clock->now();
        $preference = $this->preferences->find($userId, $type);
        if ($preference instanceof NotificationPreference) {
            $preference->choose($chosen, $now);
            $this->preferences->save($preference);
        } else {
            try {
                $this->preferences->save(NotificationPreference::create($userId, $type, $chosen, $now));
            } catch (UniqueConstraintViolationException) {
                // A concurrent choice landed first - the member can choose again.
            }
        }

        return $this->forUser($userId);
    }
}
