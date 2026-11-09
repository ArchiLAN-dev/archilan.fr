<?php

declare(strict_types=1);

namespace App\Surveys\Application\Command;

/**
 * A survey as the admin writes it (story 51.1), fields as sent: enums and dates are checked by the command. A null
 * field is a field left as it is (on a revision) or given its default (on a creation).
 */
final readonly class SurveyDraft
{
    public function __construct(
        public ?string $title = null,
        public ?string $intro = null,
        public ?string $audience = null,
        public ?bool $anonymous = null,
        public ?bool $sendEmail = null,
        public ?string $opensAt = null,
        public ?string $closesAt = null,
    ) {
    }
}
