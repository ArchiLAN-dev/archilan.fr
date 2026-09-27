<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Support\ModerationForumMessage;

/**
 * Direct messages between the project's bot and a member on Discord (stories 39.3 and 39.4). The message sent
 * is the same card the staff forum takes; the answers are read back, the bot holding no live connection.
 */
interface MemberDirectMessageInterface
{
    /** False without a bot token: nothing can be sent or read. */
    public function isConfigured(): bool;

    /**
     * @throws MemberDirectMessageException
     */
    public function send(string $discordUserId, ModerationForumMessage $message): SentDirectMessage;

    /**
     * The messages of the channel after the given one, oldest first, whoever wrote them.
     *
     * @return list<IncomingDirectMessage>
     *
     * @throws MemberDirectMessageException
     */
    public function messagesAfter(string $channelId, string $afterMessageId): array;
}
