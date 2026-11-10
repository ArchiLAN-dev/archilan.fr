<?php

declare(strict_types=1);

namespace App\Surveys\Domain\ValueObject;

/**
 * What a member gave for one question (story 51.2), as sent: checked against the question by the answer sheet. An
 * answer with no option, no text and no mark is no answer.
 */
final readonly class GivenAnswer
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

    public function isEmpty(): bool
    {
        return [] === $this->optionIds && (null === $this->text || '' === trim($this->text)) && null === $this->rating;
    }
}
