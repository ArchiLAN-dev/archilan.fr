<?php

declare(strict_types=1);

namespace App\Sessions\Application;

/**
 * Computes the named superlatives of a run from its exchange graph and each
 * slot's goal-completion time.
 *
 * Pure: no IO, no clock - the times are passed in (AC-D3/AC-A). Labels follow
 * the ArchiLAN pop-culture / cinema naming style; they are display strings and
 * can be re-themed without touching the metrics.
 *
 * "Longest road" needs a duration, but every slot shares the session start, so
 * the shared start cancels: latest goal = longest road, earliest = first to goal.
 */
final class RecapSuperlativesCalculator
{
    /**
     * @param array<string,?\DateTimeImmutable> $goalReachedBySlotName slotName => goal time (null if never reached)
     *
     * @return list<RecapSuperlative>
     */
    public function calculate(RecapGraph $graph, array $goalReachedBySlotName): array
    {
        $superlatives = [];

        $mostGenerous = $this->mostGenerous($graph);
        if (null !== $mostGenerous) {
            $superlatives[] = $mostGenerous;
        }

        $biggestHub = $this->biggestHub($graph);
        if (null !== $biggestHub) {
            $superlatives[] = $biggestHub;
        }

        $firstToGoal = $this->firstToGoal($goalReachedBySlotName);
        if (null !== $firstToGoal) {
            $superlatives[] = $firstToGoal;
        }

        $longestRoad = $this->longestRoad($goalReachedBySlotName);
        if (null !== $longestRoad) {
            $superlatives[] = $longestRoad;
        }

        return $superlatives;
    }

    private function mostGenerous(RecapGraph $graph): ?RecapSuperlative
    {
        /** @var array<string,int> $sent */
        $sent = [];
        foreach ($graph->edges as $edge) {
            $sent[$edge->fromSlotName] = ($sent[$edge->fromSlotName] ?? 0) + $edge->count;
        }

        $winner = $this->argMaxInt($sent);
        if (null === $winner) {
            return null;
        }

        return new RecapSuperlative('most_generous', 'Le Parrain', $winner, $sent[$winner]);
    }

    private function biggestHub(RecapGraph $graph): ?RecapSuperlative
    {
        /** @var array<string,array<string,true>> $targets */
        $targets = [];
        foreach ($graph->edges as $edge) {
            $targets[$edge->fromSlotName][$edge->toSlotName] = true;
        }

        /** @var array<string,int> $distinct */
        $distinct = [];
        foreach ($targets as $slotName => $set) {
            $distinct[$slotName] = \count($set);
        }

        $winner = $this->argMaxInt($distinct);
        if (null === $winner) {
            return null;
        }

        return new RecapSuperlative('biggest_hub', 'Le Facteur', $winner, $distinct[$winner]);
    }

    /**
     * @param array<string,?\DateTimeImmutable> $goalReachedBySlotName
     */
    private function firstToGoal(array $goalReachedBySlotName): ?RecapSuperlative
    {
        $winner = $this->extremeGoal($goalReachedBySlotName, earliest: true);
        if (null === $winner) {
            return null;
        }

        [$slotName, $time] = $winner;

        return new RecapSuperlative('first_to_goal', 'Speedy Gonzales', $slotName, $time->format(\DateTimeInterface::ATOM));
    }

    /**
     * @param array<string,?\DateTimeImmutable> $goalReachedBySlotName
     */
    private function longestRoad(array $goalReachedBySlotName): ?RecapSuperlative
    {
        $winner = $this->extremeGoal($goalReachedBySlotName, earliest: false);
        if (null === $winner) {
            return null;
        }

        [$slotName, $time] = $winner;

        return new RecapSuperlative('longest_road', 'Le Seigneur des Anneaux', $slotName, $time->format(\DateTimeInterface::ATOM));
    }

    /**
     * Slot with the highest value; first-inserted wins ties (deterministic).
     *
     * @param array<string,int> $values
     */
    private function argMaxInt(array $values): ?string
    {
        $winner = null;
        $best = null;
        foreach ($values as $slotName => $value) {
            if (null === $best || $value > $best) {
                $best = $value;
                $winner = $slotName;
            }
        }

        return $winner;
    }

    /**
     * The earliest (or latest) non-null goal time; first-inserted wins ties.
     *
     * @param array<string,?\DateTimeImmutable> $goalReachedBySlotName
     *
     * @return array{0: string, 1: \DateTimeImmutable}|null
     */
    private function extremeGoal(array $goalReachedBySlotName, bool $earliest): ?array
    {
        $winner = null;
        $best = null;
        foreach ($goalReachedBySlotName as $slotName => $time) {
            if (null === $time) {
                continue;
            }

            if (null === $best
                || ($earliest && $time < $best)
                || (!$earliest && $time > $best)
            ) {
                $best = $time;
                $winner = $slotName;
            }
        }

        if (null === $winner || null === $best) {
            return null;
        }

        return [$winner, $best];
    }
}
