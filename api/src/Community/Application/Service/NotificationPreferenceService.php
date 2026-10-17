<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Application\Port\MemberModerationGatewayInterface;
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
        'run_nudge' => NotificationChannel::BellAndPush,
        // Story 43.28: the moments of a personal run.
        'run_started' => NotificationChannel::BellAndPush,
        'run_joined' => NotificationChannel::Bell,
        'run_games_chosen' => NotificationChannel::Bell,
        // Story 16.23: every player of the member's draft run is ready, the moment to launch.
        'run_all_ready' => NotificationChannel::BellAndPush,
        'run_recap_ready' => NotificationChannel::Bell,
    ];

    /**
     * Story 40.6: the types that also go to Discord unless the member says otherwise - the moments of their runs.
     *
     * @var list<string>
     */
    private const array DISCORD_BY_DEFAULT = [
        'run_invitation', 'slot_unblocked', 'run_nudge', 'run_started', 'run_joined', 'run_games_chosen', 'run_all_ready', 'run_recap_ready',
    ];

    public function __construct(
        private NotificationPreferenceRepositoryInterface $preferences,
        private MemberModerationGatewayInterface $members,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Story 40.6: whether this type also goes to the member's Discord direct messages. Only a configurable type can,
     * and only one with a text to send (the push's).
     */
    public function discordFor(string $userId, string $type): bool
    {
        if (!array_key_exists($type, self::CONFIGURABLE) || !PushMessageFactory::isPushable($type)) {
            return false;
        }

        return $this->preferences->find($userId, $type)?->getDiscord() ?? in_array($type, self::DISCORD_BY_DEFAULT, true);
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
     * Story 40.6: `discord` is null for a member whose Discord is not linked - nothing could reach it.
     *
     * @return list<array{type: string, channel: string, discord: bool|null}>
     */
    public function forUser(string $userId): array
    {
        $chosen = [];
        $discord = [];
        foreach ($this->preferences->forUser($userId) as $preference) {
            $chosen[$preference->getType()] = $preference->getChannel();
            $discord[$preference->getType()] = $preference->getDiscord();
        }
        $linked = null !== $this->members->discordIdOf($userId);

        $list = [];
        foreach (self::CONFIGURABLE as $type => $default) {
            $list[] = [
                'type' => $type,
                'channel' => ($chosen[$type] ?? $default)->value,
                'discord' => $linked ? ($discord[$type] ?? in_array($type, self::DISCORD_BY_DEFAULT, true)) : null,
            ];
        }

        return $list;
    }

    /**
     * Story 40.6: also on Discord, or not, for one type; the settings as they now stand, or null for a type that does
     * not exist.
     *
     * @return list<array{type: string, channel: string, discord: bool|null}>|null
     */
    public function chooseDiscord(string $userId, string $type, bool $discord): ?array
    {
        $default = self::CONFIGURABLE[$type] ?? null;
        if (null === $default) {
            return null;
        }

        $now = $this->clock->now();
        $preference = $this->preferences->find($userId, $type);
        if ($preference instanceof NotificationPreference) {
            $preference->chooseDiscord($discord, $now);
            $this->preferences->save($preference);
        } else {
            try {
                $this->preferences->save(NotificationPreference::create($userId, $type, $default, $now, $discord));
            } catch (UniqueConstraintViolationException) {
                // A concurrent choice landed first - the member can choose again.
            }
        }

        return $this->forUser($userId);
    }

    /**
     * The member's choice for one type; the settings as they now stand, or null for a type or a channel that does
     * not exist.
     *
     * @return list<array{type: string, channel: string, discord: bool|null}>|null
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
