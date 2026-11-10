<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 52.3: the Passeur's view of one slot. Not blocked: no candidate. `ranking` says how they are ordered -
 * `exact` (the tracker measured what each one opens) or `sphere` (fallback: earliest sphere first).
 */
final readonly class PasseurCandidates
{
    public const string RANKING_EXACT = 'exact';
    public const string RANKING_SPHERE = 'sphere';

    /**
     * @param list<PasseurCandidate> $candidates
     */
    public function __construct(
        public ?\DateTimeImmutable $blockedSince,
        public string $ranking,
        public array $candidates,
    ) {
    }

    public static function notBlocked(): self
    {
        return new self(null, self::RANKING_SPHERE, []);
    }
}
