<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * One message of a DM channel between the bot and a member (story 39.4). `content` carries the links of the
 * attachments after the text.
 */
final readonly class IncomingDirectMessage
{
    public function __construct(
        public string $id,
        public string $authorId,
        public string $content,
        public string $sentAt,
    ) {
    }
}
