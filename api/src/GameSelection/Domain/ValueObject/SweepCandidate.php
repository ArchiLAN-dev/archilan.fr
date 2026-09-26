<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\ValueObject;

/**
 * One served apworld as the catalogue sweep sees it (story 38.9): its last verdict's image and date,
 * null when never tested, and whether it must be left alone (no apworld to run, a candidate in test,
 * a verdict forced by an admin, a disabled game, a test already running).
 */
final readonly class SweepCandidate
{
    public function __construct(
        public string $gameId,
        public string $apworldHash,
        public ?string $verdictImage,
        public ?string $verdictImageId,
        public ?\DateTimeImmutable $checkedAt,
        public bool $excluded,
    ) {
    }
}
