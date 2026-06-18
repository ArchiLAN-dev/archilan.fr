<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Notification;
use App\Community\Domain\NotificationRepositoryInterface;
use App\Realtime\Application\RealtimePublisher;

/**
 * In-app notification center (story 30.12): write side (emit + realtime push) and read side (recent list,
 * unread count, mark read). The actor in each payload is resolved to a display card at read time.
 */
final readonly class NotificationService implements Notifier
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 100;

    public function __construct(
        private NotificationRepositoryInterface $notifications,
        private CommunityUserDirectoryQueryInterface $directory,
        private RealtimePublisher $realtime,
    ) {
    }

    public function notify(string $recipientId, string $type, array $payload): void
    {
        $actorId = $payload['fromUserId'] ?? null;
        if ('' === $recipientId || $recipientId === $actorId) {
            return;
        }

        $notification = Notification::create($recipientId, $type, $payload, new \DateTimeImmutable());
        $this->notifications->save($notification);

        $this->realtime->userNotification($recipientId, [
            'type' => $type,
            'id' => $notification->getId(),
        ]);
    }

    /**
     * @return array{
     *     unreadCount: int,
     *     items: list<array{id: string, type: string, createdAt: string, read: bool, actor: array{slug: string, displayName: string|null, avatarUrl: string|null}|null, data: array<string, mixed>}>
     * }
     */
    public function recent(string $recipientId, int $limit): array
    {
        $notifications = $this->notifications->recentForRecipient($recipientId, $this->clampLimit($limit));

        $actorIds = [];
        foreach ($notifications as $notification) {
            $actorId = $notification->getPayload()['fromUserId'] ?? null;
            if (is_string($actorId)) {
                $actorIds[] = $actorId;
            }
        }
        $cards = [] === $actorIds ? [] : $this->directory->cards(array_values(array_unique($actorIds)));

        $items = [];
        foreach ($notifications as $notification) {
            $payload = $notification->getPayload();
            $actorId = $payload['fromUserId'] ?? null;
            $card = is_string($actorId) ? ($cards[$actorId] ?? null) : null;

            $items[] = [
                'id' => $notification->getId(),
                'type' => $notification->getType(),
                'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'read' => $notification->isRead(),
                'actor' => null === $card ? null : [
                    'slug' => $card['slug'],
                    'displayName' => $card['displayName'],
                    'avatarUrl' => $card['avatarUrl'],
                ],
                'data' => $payload,
            ];
        }

        return ['unreadCount' => $this->notifications->countUnread($recipientId), 'items' => $items];
    }

    public function unreadCount(string $recipientId): int
    {
        return $this->notifications->countUnread($recipientId);
    }

    public function markRead(string $notificationId, string $userId): string
    {
        $notification = $this->notifications->findById($notificationId);
        if (!$notification instanceof Notification) {
            return 'not_found';
        }
        if ($notification->getRecipientId() !== $userId) {
            return 'forbidden';
        }

        $notification->markRead(new \DateTimeImmutable());
        $this->notifications->flush();

        return 'ok';
    }

    public function markAllRead(string $userId): void
    {
        $this->notifications->markAllRead($userId, new \DateTimeImmutable());
    }

    private function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }
}
