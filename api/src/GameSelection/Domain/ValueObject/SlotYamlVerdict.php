<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\ValueObject;

use App\GameSelection\Domain\Enum\SlotYamlCase;

/**
 * The classification of one slot YAML after an apworld switch (story 38.7).
 */
final readonly class SlotYamlVerdict
{
    /**
     * @param list<SlotYamlIssue> $issues empty unless the case is NeedsReview
     */
    public function __construct(
        public SlotYamlCase $case,
        public array $issues = [],
    ) {
    }
}
