<?php

declare(strict_types=1);

namespace App\Realtime\Application;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class RealtimePublisher
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public static function seatCounterTopic(string $eventId): string
    {
        return sprintf('https://archilan.fr/events/%s/seat-counter', $eventId);
    }

    public static function adminRegistrationsTopic(string $eventId): string
    {
        return sprintf('https://archilan.fr/events/%s/registrations', $eventId);
    }

    public static function userNotificationsTopic(string $userId): string
    {
        return sprintf('https://archilan.fr/users/%s/notifications', $userId);
    }

    /**
     * Push a private notification to a single user's topic (best-effort; the in-app center is the source
     * of truth, this only makes it live).
     *
     * @param array<string, mixed> $payload
     */
    public function userNotification(string $userId, array $payload): void
    {
        $this->publish(new Update(
            self::userNotificationsTopic($userId),
            (string) json_encode($payload),
            true,
        ), 'realtime.user_notification_publish_failed', ['userId' => $userId]);
    }

    public function seatCounter(string $eventId, int $remainingSeats): void
    {
        $this->publish(new Update(
            self::seatCounterTopic($eventId),
            (string) json_encode(['eventId' => $eventId, 'remainingSeats' => $remainingSeats]),
        ), 'realtime.seat_counter_publish_failed', ['eventId' => $eventId]);
    }

    public function adminRegistrationCreated(string $eventId, string $registrationId, \DateTimeImmutable $at): void
    {
        $this->publish(new Update(
            self::adminRegistrationsTopic($eventId),
            (string) json_encode([
                'type' => 'registration.reserved',
                'registrationId' => $registrationId,
                'createdAt' => $at->format(\DateTimeInterface::ATOM),
            ]),
            true,
        ), 'realtime.admin_registration_publish_failed', ['eventId' => $eventId, 'registrationId' => $registrationId]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function publish(Update $update, string $errorLogKey, array $context): void
    {
        try {
            $this->hub->publish($update);
        } catch (\Throwable $e) {
            $this->logger->error($errorLogKey, [...$context, 'error' => $e->getMessage()]);
        }
    }
}
