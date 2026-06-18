<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface NotificationRepositoryInterface
{
    public function save(Notification $notification): void;

    public function findById(string $id): ?Notification;

    /**
     * Recent notifications for a recipient, newest first.
     *
     * @return list<Notification>
     */
    public function recentForRecipient(string $recipientId, int $limit): array;

    public function countUnread(string $recipientId): int;

    /**
     * Mark every unread notification of the recipient as read; returns the number updated.
     */
    public function markAllRead(string $recipientId, \DateTimeImmutable $now): int;

    public function flush(): void;
}
