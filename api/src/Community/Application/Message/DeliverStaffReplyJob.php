<?php

declare(strict_types=1);

namespace App\Community\Application\Message;

/**
 * Deliver one staff reply to the member by the bot's direct message, then to the staff forum (story 39.3).
 * Dispatched after the reply is committed; asynchronous, so Discord never holds it back.
 */
final readonly class DeliverStaffReplyJob
{
    public function __construct(public string $messageId)
    {
    }
}
