import type { HintEntry } from "./types";

/**
 * Filtering the hint list (story 9.50).
 *
 * The player could already rank a hint - prioritaire, faible prio., éviter - and that ranking
 * travels all the way to the Archipelago server (stories 9.34/9.35). On screen everything landed
 * back in one list, so on a run with forty hints the thing they had just filed was exactly what they
 * could no longer find.
 *
 * Kept apart from the panel because the interesting part is the combination of the three axes, and a
 * pure function is the only way to pin it without driving a UI.
 */

export type StateFilter = "all" | "pending" | "found";
export type PriorityFilter = "all" | "priority" | "no_priority" | "avoid" | "unspecified";
export type SideFilter = "all" | "mine" | "world";

export type HintFilters = {
  state: StateFilter;
  priority: PriorityFilter;
  side: SideFilter;
};

export const NO_FILTERS: HintFilters = { state: "all", priority: "all", side: "all" };

export const STATE_FILTERS: { value: StateFilter; label: string }[] = [
  { value: "all", label: "Tous" },
  { value: "pending", label: "En attente" },
  { value: "found", label: "Trouvés" },
];

export const PRIORITY_FILTERS: { value: PriorityFilter; label: string }[] = [
  { value: "all", label: "Toutes prio." },
  { value: "priority", label: "Prioritaires" },
  { value: "no_priority", label: "Faible prio." },
  { value: "avoid", label: "Éviter" },
  { value: "unspecified", label: "Non classé" },
];

export const SIDE_FILTERS: { value: SideFilter; label: string }[] = [
  { value: "all", label: "Tous" },
  { value: "mine", label: "Pour moi" },
  { value: "world", label: "Dans mon monde" },
];

export function isFiltering(filters: HintFilters): boolean {
  return filters.state !== "all" || filters.priority !== "all" || filters.side !== "all";
}

function matchesState(hint: HintEntry, filter: StateFilter): boolean {
  if (filter === "found") return hint.found;
  if (filter === "pending") return !hint.found;
  return true;
}

function matchesPriority(hint: HintEntry, filter: PriorityFilter): boolean {
  return filter === "all" || hint.statusName === filter;
}

/**
 * A hint touches a slot from two sides, and they call for different moves: an item coming *to* me,
 * or an item hidden *in my world* that another player is waiting for. A hint can be both at once -
 * one's own item hidden in one's own world - so it belongs to both filters rather than to neither.
 */
function matchesSide(hint: HintEntry, filter: SideFilter, slot: number): boolean {
  if (filter === "mine") return hint.receivingPlayer === slot;
  if (filter === "world") return hint.findingPlayer === slot;
  return true;
}

/** The three axes intersect: "en attente" + "prioritaires" + "pour moi" is one question. */
export function filterHints(hints: HintEntry[], filters: HintFilters, slot: number): HintEntry[] {
  return hints.filter(
    (hint) =>
      matchesState(hint, filters.state)
      && matchesPriority(hint, filters.priority)
      && matchesSide(hint, filters.side, slot),
  );
}

/** How a hint relates to the slot, as a key - "m", "w", "mw", or "" for a hint that is neither. */
export function sideOf(hint: HintEntry, slot: number): string {
  return `${hint.receivingPlayer === slot ? "m" : ""}${hint.findingPlayer === slot ? "w" : ""}`;
}

/**
 * Whether an axis is worth showing: hints that all share one value sort nothing, and five empty
 * priority chips on a slot where nobody ranked anything are noise rather than a control.
 */
export function axisIsUseful<T>(items: T[], key: (item: T) => string): boolean {
  return new Set(items.map(key)).size > 1;
}
