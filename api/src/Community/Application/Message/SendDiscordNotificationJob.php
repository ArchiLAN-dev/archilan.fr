<?php

declare(strict_types=1);

namespace App\Community\Application\Message;

/**
 * Dispatched once a notification is saved for a member who keeps its type on Discord (story 40.6), to send it as a
 * direct message from the bot.
 */
final readonly class SendDiscordNotificationJob
{
    public function __construct(
        public string $notificationId,
    ) {
    }
}
