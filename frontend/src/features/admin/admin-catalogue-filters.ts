import type { ApworldUpdate, IgnoredGame, NewGame, RemovedGame, StabilityChange } from "./admin-catalogue-sync-api";

/**
 * Per-column filters of the catalogue page's tables (story 14.12). An empty string means "no filter" on that
 * column, so a fresh filter object shows every row.
 */

export type NewGamesFilter = {
  name: string;
  availability: string;
  flag: "" | "adult" | "bundled" | "none";
  votes: "" | "with" | "without";
};

export type UpdatesFilter = {
  name: string;
  status: string;
  versions: "" | "different" | "same";
};

export type ChangesFilter = {
  name: string;
  current: string;
  next: string;
  locked: "" | "locked" | "unlocked";
};

export const EMPTY_NEW_GAMES_FILTER: NewGamesFilter = { name: "", availability: "", flag: "", votes: "" };
export const EMPTY_UPDATES_FILTER: UpdatesFilter = { name: "", status: "", versions: "" };
export const EMPTY_CHANGES_FILTER: ChangesFilter = { name: "", current: "", next: "", locked: "" };

function nameMatches(name: string, query: string): boolean {
  const q = query.toLowerCase().trim();
  return q === "" || name.toLowerCase().includes(q);
}

export function filterNewGames(games: NewGame[], f: NewGamesFilter): NewGame[] {
  return games.filter(
    (g) =>
      nameMatches(g.name, f.name) &&
      (f.availability === "" || g.availability === f.availability) &&
      (f.flag === "" ||
        (f.flag === "adult" && g.adultContent) ||
        (f.flag === "bundled" && g.bundledWithAp) ||
        (f.flag === "none" && !g.adultContent && !g.bundledWithAp)) &&
      (f.votes === "" || (f.votes === "with" ? g.votes > 0 : g.votes === 0)),
  );
}

/**
 * The deployed apworld lags behind the latest known release. An unknown latest version is never "different":
 * there is nothing to compare with.
 */
export function hasVersionGap(u: Pick<ApworldUpdate, "deployedVersion" | "latestVersion">): boolean {
  return u.latestVersion !== null && u.deployedVersion !== u.latestVersion;
}

export function filterUpdates(updates: ApworldUpdate[], f: UpdatesFilter): ApworldUpdate[] {
  return updates.filter(
    (u) =>
      nameMatches(u.gameName, f.name) &&
      (f.status === "" || u.updateStatus === f.status) &&
      (f.versions === "" || (f.versions === "different") === hasVersionGap(u)),
  );
}

export function filterChanges(changes: StabilityChange[], f: ChangesFilter): StabilityChange[] {
  return changes.filter(
    (c) =>
      nameMatches(c.gameName, f.name) &&
      (f.current === "" || c.currentAvailability === f.current) &&
      (f.next === "" || c.newAvailability === f.next) &&
      (f.locked === "" || (f.locked === "locked") === c.availabilityLocked),
  );
}

export function filterIgnored(games: IgnoredGame[], name: string): IgnoredGame[] {
  return games.filter((g) => nameMatches(g.name, name));
}

export function filterRemoved(games: RemovedGame[], name: string): RemovedGame[] {
  return games.filter((g) => nameMatches(g.gameName, name));
}
