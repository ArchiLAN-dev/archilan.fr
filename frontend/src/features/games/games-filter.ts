import type { PublicGame } from "./public-games-api";

export type SortOrder = "name-asc" | "name-desc";
export type AvailabilityFilter = "all" | "available" | "experimental";

export type CatalogFilters = {
  query: string;
  availability: AvailabilityFilter;
  ownedOnly: boolean;
  sort: SortOrder;
};

export function isOwned(game: PublicGame, ownedAppIds: Set<number>): boolean {
  return game.steamAppId !== null && ownedAppIds.has(game.steamAppId);
}

/**
 * Pure client-side catalog derivation: filter by search/availability/owned, then sort.
 * When a coupling is active (ownedAppIds non-empty), owned games are listed first.
 */
export function filterAndSortGames(
  games: PublicGame[],
  filters: CatalogFilters,
  ownedAppIds: Set<number>,
): PublicGame[] {
  const needle = filters.query.trim().toLowerCase();

  const filtered = games.filter((game) => {
    if (filters.availability !== "all" && game.availability !== filters.availability) return false;
    if (filters.ownedOnly && !isOwned(game, ownedAppIds)) return false;
    if (needle !== "") {
      const haystack = `${game.name} ${game.description}`.toLowerCase();
      if (!haystack.includes(needle)) return false;
    }
    return true;
  });

  const direction = filters.sort === "name-desc" ? -1 : 1;
  const ownedFirst = ownedAppIds.size > 0;

  return [...filtered].sort((a, b) => {
    if (ownedFirst) {
      const ownedDelta = Number(isOwned(b, ownedAppIds)) - Number(isOwned(a, ownedAppIds));
      if (ownedDelta !== 0) return ownedDelta;
    }
    return direction * a.name.localeCompare(b.name, "fr", { sensitivity: "base" });
  });
}
