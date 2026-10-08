<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Application\Support\MetricBagBuilder;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\Entity\AchievementCollection;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Exception\InvalidAchievementRuleException;
use App\Community\Domain\Repository\AchievementCollectionRepositoryInterface;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use App\Community\Domain\Service\AchievementRuleFactory;

/**
 * Story 30.53: where a member stands on one of their achievements, asked by the member alone when they open it.
 * Building the MetricBag reads every metric provider, so this runs on demand only, never with the catalogue. An
 * achievement held shows when (and whether the team gave it); the engine is monotonic, so its rule may no longer
 * hold today and is not shown. Hidden like the catalogue hides it: an inactive achievement not held, or one of a
 * secret collection the member has not opened yet, does not exist here.
 */
final readonly class AchievementProgressQuery
{
    public function __construct(
        private AchievementDefinitionRepositoryInterface $definitions,
        private AchievementGrantRepositoryInterface $grants,
        private AchievementCollectionRepositoryInterface $collections,
        private MetricBagBuilder $metrics,
        private EventCatalogueQueryInterface $events,
    ) {
    }

    /**
     * @return array{key: string, unlocked: bool, unlockedAt: string|null, byTeam: bool, progress: array<string, mixed>|null}|null
     */
    public function forMember(string $userId, string $key): ?array
    {
        $definitions = $this->definitions->all();
        $definition = array_find($definitions, static fn (AchievementDefinition $d): bool => $key === $d->getKey());
        if (!$definition instanceof AchievementDefinition) {
            return null;
        }
        $grants = [];
        foreach ($this->grants->findByUser($userId) as $grant) {
            $grants[$grant->getAchievementKey()] = $grant;
        }
        $grant = $grants[$key] ?? null;
        if (null !== $grant) {
            return ['key' => $key, 'unlocked' => true, 'unlockedAt' => $grant->getUnlockedAt()->format(\DateTimeInterface::ATOM), 'byTeam' => $grant->isByTeam(), 'progress' => null];
        }
        if (!$definition->isActive() || $this->hiddenSecret($definition, $definitions, $grants)) {
            return null;
        }

        try {
            $rule = AchievementRuleFactory::fromArray($definition->getRule());
        } catch (InvalidAchievementRuleException) {
            // A malformed rule never unlocks; there is nothing to measure.
            return ['key' => $key, 'unlocked' => false, 'unlockedAt' => null, 'byTeam' => false, 'progress' => null];
        }

        return ['key' => $key, 'unlocked' => false, 'unlockedAt' => null, 'byTeam' => false, 'progress' => $this->labelled($rule->progress($this->metrics->build($userId)))];
    }

    /**
     * @param list<AchievementDefinition> $definitions
     * @param array<string, mixed>        $grants      keyed by achievement key
     */
    private function hiddenSecret(AchievementDefinition $definition, array $definitions, array $grants): bool
    {
        $collectionId = $definition->getCollectionId();
        $collection = null === $collectionId ? null : $this->collections->findById($collectionId);
        if (!$collection instanceof AchievementCollection || !$collection->isSecret()) {
            return false;
        }

        return !array_any($definitions, static fn (AchievementDefinition $d): bool => $collectionId === $d->getCollectionId() && isset($grants[$d->getKey()]));
    }

    /**
     * Each condition gets the label of its criterion, an event's by its title.
     *
     * @param array<mixed>               $node
     * @param array<string, string>|null $eventTitles
     *
     * @return array<string, mixed>
     */
    private function labelled(array $node, ?array $eventTitles = null): array
    {
        if (null === $eventTitles) {
            $eventTitles = [];
            foreach ($this->events->selectableEvents() as $event) {
                $eventTitles[$event['id']] = $event['title'];
            }
        }
        $out = [];
        foreach ($node as $field => $value) {
            $out[(string) $field] = $value;
        }
        $fact = $node['fact'] ?? null;
        if (is_string($fact)) {
            $eventId = AchievementMetricCatalog::eventIdFromFact($fact);
            $out['label'] = null !== $eventId
                ? sprintf('Objectif atteint à « %s »', $eventTitles[$eventId] ?? 'un événement')
                : AchievementMetricCatalog::facts()[$fact] ?? $fact;
        }
        $rules = $node['rules'] ?? null;
        if (is_array($rules)) {
            $out['rules'] = array_values(array_map(fn (mixed $child): array => is_array($child) ? $this->labelled($child, $eventTitles) : [], $rules));
        }

        return $out;
    }
}
