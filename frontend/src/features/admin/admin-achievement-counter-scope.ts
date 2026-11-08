import type { AchievementFormOptions } from "./admin-achievements-api";

// Counter facts (story 30.57): `counter:{counterId}`, an admin counter of checks or items.
export const COUNTER_PREFIX = "counter:";

/** The value of the criterion picker's « Compteur… » entry; the counter itself is picked next to it. */
export const COUNTER_PICK = "counter:";

export function isCounterFact(fact: string): boolean {
  return fact.startsWith(COUNTER_PREFIX);
}

export function counterIdOfFact(fact: string): string | null {
  return fact.startsWith(COUNTER_PREFIX) && fact.length > COUNTER_PREFIX.length ? fact.slice(COUNTER_PREFIX.length) : null;
}

export function counterFact(counterId: string): string {
  return COUNTER_PREFIX + counterId;
}

/** The counter's label, or a marker when it no longer exists. */
export function counterLabel(fact: string, options: AchievementFormOptions): string {
  const id = counterIdOfFact(fact);
  const counter = id === null ? undefined : options.counters.find((c) => c.id === id);
  return counter ? counter.label : "compteur supprimé";
}
