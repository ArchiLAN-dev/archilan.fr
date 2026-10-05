<?php

declare(strict_types=1);

namespace App\Wallet\Domain\ValueObject;

use App\Wallet\Domain\Enum\QuestMetric;

/**
 * One objective of a quest (story 41.15): reach `target` of a metric over the week. A quest is accomplished when
 * every one of its objectives is.
 */
final readonly class QuestObjective
{
    public const int MIN_TARGET = 1;
    public const int MAX_TARGET = 10000;

    public function __construct(
        public QuestMetric $metric,
        public int $target,
    ) {
        if ($target < self::MIN_TARGET || $target > self::MAX_TARGET) {
            throw new \DomainException('quest_objective_target_invalid');
        }
    }

    public function isReachedBy(int $count): bool
    {
        return $count >= $this->target;
    }

    /** @return array{metric: string, target: int} */
    public function toArray(): array
    {
        return ['metric' => $this->metric->value, 'target' => $this->target];
    }

    /**
     * The objectives stored with a quest; an unreadable entry is dropped rather than failing the whole quest.
     *
     * @param array<mixed> $rows
     *
     * @return list<self>
     */
    public static function listFromArray(array $rows): array
    {
        $objectives = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['metric'] ?? null) || !\is_int($row['target'] ?? null)) {
                continue;
            }
            $metric = QuestMetric::tryFrom($row['metric']);
            if (null === $metric || $row['target'] < self::MIN_TARGET || $row['target'] > self::MAX_TARGET) {
                continue;
            }
            $objectives[] = new self($metric, $row['target']);
        }

        return $objectives;
    }
}
