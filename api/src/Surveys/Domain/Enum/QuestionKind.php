<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Enum;

/** The kind of answer a survey question takes (story 51.1). */
enum QuestionKind: string
{
    case Single = 'single';
    case Multiple = 'multiple';
    case Text = 'text';
    /** A mark from 1 to 5. */
    case Rating = 'rating';

    /** Only a choice question has options. */
    public function hasOptions(): bool
    {
        return self::Single === $this || self::Multiple === $this;
    }
}
