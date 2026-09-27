<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Support\ModerationForumMessage;

/**
 * The staff forum where each member's moderation case has its post (story 39.1). Implemented on Discord
 * with the project's existing bot.
 */
interface ModerationForumInterface
{
    /** False when no forum is configured: moderation then stays on the site only. */
    public function isConfigured(): bool;

    /**
     * Open a new post with its first message, tagged after {@see ModerationForumMessage::$tag}.
     *
     * @return string the post's id
     *
     * @throws ModerationForumDeliveryException
     */
    public function openThread(string $title, ModerationForumMessage $message): string;

    /**
     * Post a message in an existing post, reopening it if archived and retagging it.
     *
     * @throws ModerationForumDeliveryException
     */
    public function post(string $threadId, ModerationForumMessage $message): void;
}
