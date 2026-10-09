<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\NotificationPreference;

interface NotificationPreferenceRepositoryInterface
{
    public function find(string $userId, string $type): ?NotificationPreference;

    /**
     * @return list<NotificationPreference>
     */
    public function forUser(string $userId): array;

    public function save(NotificationPreference $preference): void;
}
