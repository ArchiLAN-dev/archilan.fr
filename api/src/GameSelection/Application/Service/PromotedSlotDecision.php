<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Service;

/**
 * The fate of one slot's YAML after an apworld promotion (story 38.7): `replacementYaml` is the new
 * default when the player never touched it, null to keep theirs; `reviewReasons` is empty unless the
 * kept YAML no longer holds.
 */
final readonly class PromotedSlotDecision
{
    /**
     * @param list<string> $reviewReasons
     */
    public function __construct(
        public ?string $replacementYaml,
        public array $reviewReasons,
    ) {
    }

    public function needsReview(): bool
    {
        return [] !== $this->reviewReasons;
    }
}
