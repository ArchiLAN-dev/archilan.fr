import type { AchievementFormOptions } from "./admin-achievements-api";

// Event-goal facts (story 30.32): a generic `eventsWithGoal` or a per-event `event_goal:{eventId}`.
export const EVENTS_FACT = "eventsWithGoal";
export const EVENT_GOAL_PREFIX = "event_goal:";

export function isEventScopedFact(fact: string): boolean {
  return fact === EVENTS_FACT || fact.startsWith(EVENT_GOAL_PREFIX);
}

export function eventIdOfFact(fact: string): string | null {
  return fact.startsWith(EVENT_GOAL_PREFIX) ? fact.slice(EVENT_GOAL_PREFIX.length) : null;
}

/** Builds the scoped fact key for an event id, or the generic fact when no event is selected. */
export function eventScopedFact(eventId: string): string {
  return eventId === "" ? EVENTS_FACT : EVENT_GOAL_PREFIX + eventId;
}

/** Human label for a fact in a rule summary — resolves a scoped event fact to its event title. */
export function factLabel(fact: string, options: AchievementFormOptions): string {
  const eventId = eventIdOfFact(fact);
  if (eventId !== null) {
    const event = options.events.find((e) => e.id === eventId);
    return `Objectif — ${event ? event.title : "événement supprimé"}`;
  }
  return options.facts.find((f) => f.key === fact)?.label ?? fact;
}