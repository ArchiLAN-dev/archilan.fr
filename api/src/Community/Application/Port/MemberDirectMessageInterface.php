<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Support\ModerationForumMessage;

/**
 * A direct message from the project's bot to a member on Discord (story 39.3). The message is the same card
 * the staff forum takes.
 */
interface MemberDirectMessageInterface
{
    /** False without a bot token: nothing can be sent. */
    public function isConfigured(): bool;

    /**
     * @throws MemberDirectMessageException
     */
    public function send(string $discordUserId, ModerationForumMessage $message): void;
}
