import type { PublicGame } from "./public-games-api";

export type SortOrder = "name-asc" | "name-desc";
export type AvailabilityFilter = "all" | "available" | "experimental";

/**
 * Which of the player's lists the catalog is narrowed to (story 28.14). Exclusive on purpose:
 * "mes jeux" and "à essayer" are two answers to the same question, not two criteria, and their
 * intersection has no obvious reading.
 */
export type ListFilter = "all" | "owned" | "planned";

export type CatalogFilters = {
  query: string;
  availability: AvailabilityFilter;
  list: ListFilter;
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
 * Pure client-side catalog derivation: filter by search/availability/list, then sort by name.
 *
 * The two lists are read separately (story 28.14): "à essayer" is never folded into `isOwned`, so
 * wanting a game never makes the catalog claim you have it.
 */
export function filterAndSortGames(
  games: PublicGame[],
  filters: CatalogFilters,
  ownedAppIds: Set<number>,
  ownedGameIds: ReadonlySet<string> = new Set(),
  plannedGameIds: ReadonlySet<string> = new Set(),
): PublicGame[] {
  const needle = filters.query.trim().toLowerCase();
  const selectedCategories = new Set(filters.categories);

  const filtered = games.filter((game) => {
    if (filters.availability !== "all" && game.availability !== filters.availability) return false;
    if (filters.list === "owned" && !isOwned(game, ownedAppIds, ownedGameIds)) return false;
    if (filters.list === "planned" && !plannedGameIds.has(game.id)) return false;
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
