import type { FeedEvent } from "./feed-api";

/**
 * Pure filters for the exchange log (story 32.11): free-text search on item/location names and an
 * exchange-type facet. All client-side over the already-fetched feed - the log narrows *before* its
 * row cap, so a match late in a long run is still found.
 */

export type LogFacet = "all" | "received" | "sent" | "local";

/** Lowercases and strips diacritics (NFD combining marks), so "épée" matches "Epee" and vice versa. */
export function normalizeSearch(value: string): string {
  return value.normalize("NFD").replace(/\p{Diacritic}/gu, "").toLowerCase();
}

/**
 * Substring match on the item or location name, against an already-normalized query
 * ({@link normalizeSearch}). An empty query matches everything.
 */
export function matchesSearch(event: FeedEvent, normalizedQuery: string): boolean {
  if (normalizedQuery === "") return true;
  return [event.item.name, event.location.name].some(
    (name) => name !== null && normalizeSearch(name).includes(normalizedQuery),
  );
}

/**
 * The facet is relative to the shown players (the player filter): "sent" keeps finds a shown player
 * made for someone else, "received" keeps items a shown player got from someone else, "local" keeps
 * self-finds. With every player shown, "sent" and "received" both mean "all transfers" - still a
 * useful split from "local"; isolate one player and they read as "what X sent" / "what X received".
 */
export function matchesFacet(event: FeedEvent, facet: LogFacet, shownSlots: ReadonlySet<number>): boolean {
  if (facet === "all") return true;
  const local = event.sender.slot !== null && event.sender.slot === event.receiver.slot;
  if (facet === "local") return local;
  if (local) return false;
  if (facet === "sent") return event.sender.slot !== null && shownSlots.has(event.sender.slot);
  return event.receiver.slot !== null && shownSlots.has(event.receiver.slot);
}
