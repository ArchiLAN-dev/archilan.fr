<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * The set of derived facts an achievement rule is evaluated against (story 30.16). Assembled in the
 * application layer from one or more metric providers; an unknown fact reads as 0.
 */
final readonly class MetricBag
{
    /**
     * @param array<string, int> $facts
     */
    public function __construct(private array $facts)
    {
    }

    public function get(string $fact): int
    {
        return $this->facts[$fact] ?? 0;
    }
}
