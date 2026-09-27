<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * Where the bot's direct message landed (story 39.4): the member's DM channel, read later for their answers,
 * and the message itself, from which those answers are read.
 */
final readonly class SentDirectMessage
{
    public function __construct(
        public string $channelId,
        public string $messageId,
    ) {
    }
}
