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
    // Story 41.66: the games of some platform families (`scopeId`: the families, `|` between them), or the games
    // put online in the last days before the week (`scopeId`: the number of days).
    public const string SCOPE_PLATFORM = 'platform';
    public const string SCOPE_RECENT = 'recent';

    public const string FAMILY_SEPARATOR = '|';
    public const int MIN_RECENT_DAYS = 7;
    public const int MAX_RECENT_DAYS = 90;

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
        if (null !== $scope && !\in_array($scope, $metric->scopes(), true)) {
            throw new \DomainException('quest_objective_scope_invalid');
        }
        if (self::SCOPE_RECENT === $scope && null === $this->recentDays()) {
            throw new \DomainException('quest_objective_scope_invalid');
        }
        if (self::SCOPE_PLATFORM === $scope && [] === $this->families()) {
            throw new \DomainException('quest_objective_scope_invalid');
        }
    }

    /**
     * Story 41.66: the platform families an objective aims at, sorted and without duplicates, so the same choice
     * always makes the same key.
     *
     * @return list<string>
     */
    public function families(): array
    {
        return self::SCOPE_PLATFORM === $this->scope && null !== $this->scopeId ? self::familiesOf($this->scopeId) : [];
    }

    /**
     * The families of a platform target's `scopeId`, sorted and without duplicates.
     *
     * @return list<string>
     */
    public static function familiesOf(string $scopeId): array
    {
        $families = array_values(array_unique(array_filter(array_map(trim(...), explode(self::FAMILY_SEPARATOR, $scopeId)), static fn (string $family): bool => '' !== $family)));
        sort($families);

        return $families;
    }

    /** Story 41.66: how many days before the week a game may have been put online to count, null when not a number in bounds. */
    public function recentDays(): ?int
    {
        if (self::SCOPE_RECENT !== $this->scope || null === $this->scopeId || 1 !== preg_match('/^\d{1,3}$/', $this->scopeId)) {
            return null;
        }
        $days = (int) $this->scopeId;

        return $days >= self::MIN_RECENT_DAYS && $days <= self::MAX_RECENT_DAYS ? $days : null;
    }

    /**
     * Story 41.66: the name of a target that needs no lookup - « jeu Super Nintendo ou Nintendo 64 », « jeu mis en
     * ligne ces 30 derniers jours » -, null for a game or an event (named by the catalog).
     */
    public function selfName(): ?string
    {
        $families = $this->families();
        if ([] !== $families) {
            $last = array_pop($families);

            return 'jeu '.([] === $families ? $last : implode(', ', $families).' ou '.$last);
        }
        $days = $this->recentDays();

        return null === $days ? null : sprintf('jeu mis en ligne ces %d derniers jours', $days);
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
