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
    // Story 41.67: a game drawn each week the quest is served, from a pool (`scopeId`: `catalog`, or `recent:{days}`
    // for the new releases). Only the template says it: once served, the objective aims at the drawn game.
    public const string SCOPE_DRAWN = 'drawn';
    public const string POOL_CATALOG = 'catalog';
    public const string POOL_RECENT = 'recent:';

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
        if ((self::SCOPE_RECENT === $scope || self::SCOPE_DRAWN === $scope) && null === $this->recentDays() && self::POOL_CATALOG !== $this->pool()) {
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

    /**
     * Story 41.66: how many days before the week a game may have been put online to count, null when not a number in
     * bounds. Story 41.67: the same window for a game drawn among the new releases.
     */
    public function recentDays(): ?int
    {
        $days = match ($this->scope) {
            self::SCOPE_RECENT => $this->scopeId,
            self::SCOPE_DRAWN => null !== $this->scopeId && str_starts_with($this->scopeId, self::POOL_RECENT) ? substr($this->scopeId, \strlen(self::POOL_RECENT)) : null,
            default => null,
        };
        if (null === $days || 1 !== preg_match('/^\d{1,3}$/', $days)) {
            return null;
        }
        $days = (int) $days;

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
        if (self::SCOPE_DRAWN === $this->scope) {
            return null === $days ? 'jeu tiré au sort dans le catalogue' : sprintf('jeu tiré au sort parmi les nouveautés de %d jours', $days);
        }

        return null === $days ? null : sprintf('jeu mis en ligne ces %d derniers jours', $days);
    }

    /** Story 41.67: the pool a drawn game comes from (`catalog`, `recent:30`), null for another target. */
    public function pool(): ?string
    {
        return self::SCOPE_DRAWN === $this->scope ? $this->scopeId : null;
    }

    /** Story 41.67: the objective as served, aimed at the game drawn for the week. */
    public function drawnOn(string $gameId): self
    {
        return self::SCOPE_DRAWN === $this->scope ? new self($this->metric, $this->target, self::SCOPE_GAME, $gameId) : $this;
    }

    /**
     * Every objective reached with what the member did over the week (none: never).
     *
     * @param list<self>         $objectives
     * @param array<string, int> $counts     objective key => count
     */
    public static function allReached(array $objectives, array $counts): bool
    {
        foreach ($objectives as $objective) {
            if (!$objective->isReachedBy($counts[$objective->key()] ?? 0)) {
                return false;
            }
        }

        return [] !== $objectives;
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
