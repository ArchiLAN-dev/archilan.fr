<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Application\Support\MetricBagBuilder;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\Entity\AchievementCollection;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Exception\InvalidAchievementRuleException;
use App\Community\Domain\Repository\AchievementCollectionRepositoryInterface;
use App\Community\Domain\Repository\AchievementCounterRepositoryInterface;
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
        private AchievementCounterRepositoryInterface $counters,
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
     * Each condition gets the label of its criterion, an event's by its title, a counter's (story 30.57) by its label.
     *
     * @param array<mixed> $node
     *
     * @return array<string, mixed>
     */
    private function labelled(array $node): array
    {
        $eventTitles = [];
        foreach ($this->events->selectableEvents() as $event) {
            $eventTitles[$event['id']] = $event['title'];
        }
        $counterLabels = [];
        foreach ($this->counters->findByIds(AchievementMetricCatalog::counterIdsIn($node)) as $counter) {
            $counterLabels[$counter->getId()] = $counter->getLabel();
        }

        return $this->labelledWith($node, $eventTitles, $counterLabels);
    }

    /**
     * @param array<mixed>          $node
     * @param array<string, string> $eventTitles
     * @param array<string, string> $counterLabels
     *
     * @return array<string, mixed>
     */
    private function labelledWith(array $node, array $eventTitles, array $counterLabels): array
    {
        $out = [];
        foreach ($node as $field => $value) {
            $out[(string) $field] = $value;
        }
        $fact = $node['fact'] ?? null;
        if (is_string($fact)) {
            $eventId = AchievementMetricCatalog::eventIdFromFact($fact);
            $counterId = AchievementMetricCatalog::counterIdFromFact($fact);
            $out['label'] = match (true) {
                null !== $eventId => sprintf('Objectif atteint à « %s »', $eventTitles[$eventId] ?? 'un événement'),
                null !== $counterId => $counterLabels[$counterId] ?? 'Compteur supprimé',
                default => AchievementMetricCatalog::facts()[$fact] ?? $fact,
            };
        }
        $rules = $node['rules'] ?? null;
        if (is_array($rules)) {
            $out['rules'] = array_values(array_map(fn (mixed $child): array => is_array($child) ? $this->labelledWith($child, $eventTitles, $counterLabels) : [], $rules));
        }

        return $out;
    }
}
