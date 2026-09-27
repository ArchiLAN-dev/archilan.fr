<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

/**
 * One message of a member's case in the staff forum (story 39.1): a titled card with fields, and the forum
 * tag the post takes after it.
 */
final readonly class ModerationForumMessage
{
    /**
     * @param list<array{name: string, value: string}> $fields
     */
    public function __construct(
        public string $title,
        public string $description,
        public int $color,
        public array $fields,
        public ?string $tag,
    ) {
    }
}
