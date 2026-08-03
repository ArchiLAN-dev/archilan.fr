import type { PublicGame } from "./public-games-api";

export type SortOrder = "name-asc" | "name-desc";
export type AvailabilityFilter = "all" | "available" | "experimental";

export type CatalogFilters = {
  query: string;
  availability: AvailabilityFilter;
  ownedOnly: boolean;
  sort: SortOrder;
  categories: string[];
};

export const STEAM_CATEGORY = "Steam";

/** Minimal shape needed for categories/ownership - satisfied by PublicGame and the run's AvailableGame. */
export type Categorizable = { id: string; platforms: string[]; steamAppId: number | null };

/**
 * Owned through either source (story 28.13): a coupled Steam library, or the player's own ArchiLAN
 * list. They are unioned here at read time and never merged in storage, so re-coupling Steam cannot
 * wipe a manual mark - and a manual mark is the only way to claim a game that has no `steamAppId`
 * at all, which is most of this catalog.
 */
export function isOwned(
  game: Categorizable,
  ownedAppIds: Set<number>,
  ownedGameIds: ReadonlySet<string> = new Set(),
): boolean {
  if (ownedGameIds.has(game.id)) return true;

  return game.steamAppId !== null && ownedAppIds.has(game.steamAppId);
}

/** A game's category set: its platform families plus the "Steam" store facet when available. */
export function categoriesOf(game: Categorizable): string[] {
  return game.steamAppId !== null ? [...game.platforms, STEAM_CATEGORY] : game.platforms;
}

/** Distinct category chips across the catalog (platform families + "Steam"), sorted. */
export function allCategories(games: Categorizable[]): string[] {
  const set = new Set<string>();
  for (const game of games) {
    for (const category of categoriesOf(game)) set.add(category);
  }
  return [...set].sort((a, b) => a.localeCompare(b, "fr", { sensitivity: "base" }));
}

/**
 * Pure client-side catalog derivation: filter by search/availability/owned, then sort by name.
 */
export function filterAndSortGames(
  games: PublicGame[],
  filters: CatalogFilters,
  ownedAppIds: Set<number>,
  ownedGameIds: ReadonlySet<string> = new Set(),
): PublicGame[] {
  const needle = filters.query.trim().toLowerCase();
  const selectedCategories = new Set(filters.categories);

  const filtered = games.filter((game) => {
    if (filters.availability !== "all" && game.availability !== filters.availability) return false;
    if (filters.ownedOnly && !isOwned(game, ownedAppIds, ownedGameIds)) return false;
    if (selectedCategories.size > 0 && !categoriesOf(game).some((c) => selectedCategories.has(c))) {
      return false;
    }
    if (needle !== "") {
      const haystack = `${game.name} ${game.description}`.toLowerCase();
      if (!haystack.includes(needle)) return false;
    }
    return true;
  });

  const direction = filters.sort === "name-desc" ? -1 : 1;

  return [...filtered].sort(
    (a, b) => direction * a.name.localeCompare(b.name, "fr", { sensitivity: "base" }),
  );
}
