<?php

declare(strict_types=1);

namespace App\Community\Application\Message;

/**
 * Post one member's message to the moderation in their case's staff forum post (story 39.2). Dispatched
 * after the message is committed; asynchronous, so Discord never holds it back.
 */
final readonly class PostModerationMessageToForumJob
{
    public function __construct(public string $messageId)
    {
    }
}
