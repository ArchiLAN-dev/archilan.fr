import { categoriesOf, isOwned, type ListFilter } from "@/features/games/games-filter";
import type { GameSelectionGame } from "./personal-runs-api";

/**
 * What the run's game picker narrows on (story 28.15).
 *
 * `list` is exclusive - "mes jeux" and "à essayer" are two answers to one question. `recentOnly`
 * and `categories` are separate axes and stay cumulable with either.
 */
export type RunGameFilters = {
  query: string;
  list: ListFilter;
  recentOnly: boolean;
  categories: string[];
};

/** The coupled Steam library and the player's own lists, as the picker reads them. */
export type PlayerGameSources = {
  steamAppIds: Set<number>;
  ownedGameIds: ReadonlySet<string>;
  plannedGameIds: ReadonlySet<string>;
};

/** Which filters have anything behind them - a filter with an empty source is not offered. */
export type RunFilterSources = {
  hasRecent: boolean;
  hasOwnership: boolean;
  hasPlanned: boolean;
};

export const RECENT_FILTER = "__recent";
export const OWNED_FILTER = "list:owned";
export const PLANNED_FILTER = "list:planned";

/**
 * The picker's predicate, as pure code so it can be tested without mounting the page.
 *
 * The owned filter unions both sources exactly like the public catalog (story 28.13): this page
 * used to know the Steam coupling alone, so a game with no `steamAppId` marked by hand was
 * invisible here and visible under /jeux. The planned list is never folded into that union -
 * wanting a game must not make the picker claim you have it.
 */
export function filterRunGames(
  games: GameSelectionGame[],
  filters: RunGameFilters,
  sources: PlayerGameSources,
  recentGameIds: ReadonlySet<string>,
): GameSelectionGame[] {
  const needle = filters.query.trim().toLowerCase();
  const selectedCategories = new Set(filters.categories);

  return games.filter((game) => {
    if (needle !== "") {
      const haystack = `${game.name} ${game.description}`.toLowerCase();
      if (!haystack.includes(needle)) return false;
    }
    if (filters.list === "owned" && !isOwned(game, sources.steamAppIds, sources.ownedGameIds)) {
      return false;
    }
    if (filters.list === "planned" && !sources.plannedGameIds.has(game.id)) return false;
    if (filters.recentOnly && !recentGameIds.has(game.id)) return false;
    if (selectedCategories.size > 0 && !categoriesOf(game).some((c) => selectedCategories.has(c))) {
      return false;
    }
    return true;
  });
}

/**
 * Default view: bubble recently-played games to the top in recency order, minus the ones already
 * selected (they live under "Ma sélection"). A live search reverts to the flat list - once the
 * player is naming what they want, a pinned block only pushes the answer down.
 */
export function orderRunGames(
  games: GameSelectionGame[],
  recentRank: ReadonlyMap<string, number>,
  selectedGameIds: ReadonlySet<string>,
  query: string,
): GameSelectionGame[] {
  if (query.trim() !== "" || recentRank.size === 0) return games;

  const pinned = games
    .filter((g) => recentRank.has(g.id) && !selectedGameIds.has(g.id))
    .sort((a, b) => (recentRank.get(a.id) ?? 0) - (recentRank.get(b.id) ?? 0));
  if (pinned.length === 0) return games;

  const pinnedIds = new Set(pinned.map((g) => g.id));

  return [...pinned, ...games.filter((g) => !pinnedIds.has(g.id))];
}

/**
 * The options the picker offers, which is where exclusivity lives: the active list filter is not
 * offered again, and picking the other one replaces it rather than adding to it. A filter whose
 * source is empty is never offered at all.
 */
export function runFilterOptions(
  sources: RunFilterSources,
  filters: RunGameFilters,
): { value: string; label: string }[] {
  return [
    ...(sources.hasRecent && !filters.recentOnly
      ? [{ value: RECENT_FILTER, label: "Récemment joués" }]
      : []),
    ...(sources.hasOwnership && filters.list !== "owned"
      ? [{ value: OWNED_FILTER, label: "Mes jeux" }]
      : []),
    ...(sources.hasPlanned && filters.list !== "planned"
      ? [{ value: PLANNED_FILTER, label: "À essayer" }]
      : []),
  ];
}
