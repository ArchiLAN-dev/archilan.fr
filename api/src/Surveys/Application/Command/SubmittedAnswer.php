<?php

declare(strict_types=1);

namespace App\Surveys\Application\Command;

/** One answer as the member sends it (story 51.2), checked by the command against its question. */
final readonly class SubmittedAnswer
{
    /**
     * @param list<string> $optionIds
     */
    public function __construct(
        public string $questionId,
        public array $optionIds = [],
        public ?string $text = null,
        public ?int $rating = null,
    ) {
    }
}
