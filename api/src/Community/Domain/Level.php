<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Steam-style level derived from XP. The cost to go from level L to L+1 is 100*(L+1) XP, so the total to
 * reach level L is 50*L*(L+1) - a gently accelerating curve. Pure and deterministic.
 */
final readonly class Level
{
    private const MAX_LEVEL = 999;

    public function __construct(
        public int $level,
        public int $xpIntoLevel,
        public int $xpForNextLevel,
    ) {
    }

    public static function fromXp(int $xp): self
    {
        $remaining = max(0, $xp);
        $level = 0;

        while ($level < self::MAX_LEVEL) {
            $cost = 100 * ($level + 1);
            if ($remaining < $cost) {
                return new self($level, $remaining, $cost);
            }
            $remaining -= $cost;
            ++$level;
        }

        return new self(self::MAX_LEVEL, 0, 100 * (self::MAX_LEVEL + 1));
    }
}
