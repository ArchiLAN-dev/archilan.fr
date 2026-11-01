<?php

declare(strict_types=1);

namespace App\Wallet\Domain\ValueObject;

/**
 * One tier of a quest (story 41.68): reach `target` on its only objective, earn `reward`. The first tier is the
 * quest's own target and reward; each tier reached is paid once, on top of the ones before.
 */
final readonly class QuestTier
{
    public function __construct(
        public int $target,
        public int $reward,
    ) {
    }

    /** @return array{target: int, reward: int} */
    public function toArray(): array
    {
        return ['target' => $this->target, 'reward' => $this->reward];
    }

    /**
     * The tiers stored with a quest; an unreadable one is dropped.
     *
     * @param array<mixed> $rows
     *
     * @return list<self>
     */
    public static function listFromArray(array $rows): array
    {
        $tiers = [];
        foreach ($rows as $row) {
            if (\is_array($row) && \is_int($row['target'] ?? null) && \is_int($row['reward'] ?? null)) {
                $tiers[] = new self($row['target'], $row['reward']);
            }
        }

        return $tiers;
    }

    /**
     * The tiers (1-based numbers) a count reaches.
     *
     * @param list<self> $tiers
     *
     * @return list<int>
     */
    public static function reachedBy(array $tiers, int $count): array
    {
        $reached = [];
        foreach ($tiers as $index => $tier) {
            if ($count >= $tier->target) {
                $reached[] = $index + 1;
            }
        }

        return $reached;
    }
}
