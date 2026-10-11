<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/**
 * Where a member wants one type of notification to reach them (story 43.11b): the bell and their devices, the bell
 * only, or nowhere.
 */
enum NotificationChannel: string
{
    case BellAndPush = 'bell_push';
    case Bell = 'bell';
    case None = 'none';

    public function reachesBell(): bool
    {
        return self::None !== $this;
    }

    public function reachesDevices(): bool
    {
        return self::BellAndPush === $this;
    }
}
