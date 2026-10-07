<?php

declare(strict_types=1);

namespace App\Wallet\Domain\ValueObject;

use App\Wallet\Domain\Enum\QuestMetric;

/**
 * One objective of a quest (story 41.15): reach `target` of a metric over the week. A quest is accomplished when
 * every one of its objectives is. Story 41.18: an objective may aim at one game or one event - it then counts only
 * the sessions of that game or event.
 */
final readonly class QuestObjective
{
    public const int MIN_TARGET = 1;
    public const int MAX_TARGET = 10000;

    public const string SCOPE_GAME = 'game';
    public const string SCOPE_EVENT = 'event';

    /** The metrics read from sessions, the only ones a game or an event can narrow. */
    public const array SCOPABLE = [QuestMetric::Goals, QuestMetric::Checks, QuestMetric::Sessions];

    public function __construct(
        public QuestMetric $metric,
        public int $target,
        public ?string $scope = null,
        public ?string $scopeId = null,
    ) {
        if ($target < self::MIN_TARGET || $target > self::MAX_TARGET) {
            throw new \DomainException('quest_objective_target_invalid');
        }
        if ((null === $scope) !== (null === $scopeId) || '' === $scopeId) {
            throw new \DomainException('quest_objective_scope_invalid');
        }
        if (null !== $scope && (!\in_array($scope, [self::SCOPE_GAME, self::SCOPE_EVENT], true) || !\in_array($metric, self::SCOPABLE, true))) {
            throw new \DomainException('quest_objective_scope_invalid');
        }
    }

    /** What the objective counts: its metric, narrowed to its game or event (`checks@game:{id}`). */
    public function key(): string
    {
        return null === $this->scope ? $this->metric->value : sprintf('%s@%s:%s', $this->metric->value, $this->scope, $this->scopeId);
    }

    public function isReachedBy(int $count): bool
    {
        return $count >= $this->target;
    }

    /** @return array{metric: string, target: int, scope?: string, scopeId?: string} */
    public function toArray(): array
    {
        $row = ['metric' => $this->metric->value, 'target' => $this->target];
        if (null !== $this->scope && null !== $this->scopeId) {
            $row['scope'] = $this->scope;
            $row['scopeId'] = $this->scopeId;
        }

        return $row;
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
            if (null === $metric) {
                continue;
            }
            try {
                $objectives[] = new self(
                    $metric,
                    $row['target'],
                    \is_string($row['scope'] ?? null) ? $row['scope'] : null,
                    \is_string($row['scopeId'] ?? null) ? $row['scopeId'] : null,
                );
            } catch (\DomainException) {
                continue;
            }
        }

        return $objectives;
    }
}
