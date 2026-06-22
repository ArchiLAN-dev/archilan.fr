<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementMetricCatalog;

/**
 * Event-participation achievement facts (story 30.32): a generic `eventsWithGoal` count plus a sparse
 * per-event `event_goal:{eventId}` = 1 for every event the user won a goal in. New combinable facts with
 * no change to the rule engine or recompute flow.
 */
final readonly class EventParticipationMetricProvider implements AchievementMetricProviderInterface
{
    public function __construct(private EventParticipationQueryInterface $events)
    {
    }

    public function metricsFor(string $userId): array
    {
        $eventIds = $this->events->eventIdsWithGoal($userId);

        $facts = [AchievementMetricCatalog::FACT_EVENTS_WITH_GOAL => count($eventIds)];
        foreach ($eventIds as $eventId) {
            $facts[AchievementMetricCatalog::EVENT_GOAL_PREFIX.$eventId] = 1;
        }

        return $facts;
    }
}
