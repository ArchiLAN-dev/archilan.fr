<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Support\WebPushMessage;

/**
 * A notification sent as a direct message from the bot (story 40.6): the push's text and link, on Discord.
 */
interface NotificationDirectMessageInterface
{
    /** False without a bot token, or while the notifications on Discord are switched off. */
    public function isConfigured(): bool;

    /**
     * @throws MemberDirectMessageException transient (worth a retry) or not (closed DMs, member gone)
     */
    public function send(string $discordUserId, WebPushMessage $message): void;
}
