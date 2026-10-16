import {
  EMPTY_CHANGES_FILTER,
  EMPTY_NEW_GAMES_FILTER,
  EMPTY_UPDATES_FILTER,
  filterChanges,
  filterNewGames,
  filterRemoved,
  filterUpdates,
  hasVersionGap,
} from "./admin-catalogue-filters";
import type { ApworldUpdate, NewGame, StabilityChange } from "./admin-catalogue-sync-api";

function game(name: string, over: Partial<NewGame> = {}): NewGame {
  return { name, availability: "available", bundledWithAp: false, adultContent: false, links: [], votes: 0, ...over };
}

function update(gameName: string, over: Partial<ApworldUpdate> = {}): ApworldUpdate {
  return {
    gameId: gameName,
    gameName,
    deployedVersion: "1.0.0",
    latestVersion: "1.0.0",
    releaseUrl: null,
    publishedAt: null,
    updateStatus: "up_to_date",
    ...over,
  };
}

function change(gameName: string, over: Partial<StabilityChange> = {}): StabilityChange {
  return {
    gameId: gameName,
    gameName,
    currentAvailability: "available",
    newAvailability: "experimental",
    availabilityLocked: false,
    ...over,
  };
}

const names = (rows: { name?: string; gameName?: string }[]) => rows.map((r) => r.name ?? r.gameName);

describe("catalogue filters", () => {
  it("shows every row with an empty filter", () => {
    const games = [game("A"), game("B", { adultContent: true })];
    expect(filterNewGames(games, EMPTY_NEW_GAMES_FILTER)).toEqual(games);
  });

  it("filters new games on each column", () => {
    const games = [
      game("Factorio", { bundledWithAp: true, votes: 2 }),
      game("Adult Game", { adultContent: true, availability: "experimental" }),
      game("Plain Game"),
    ];

    expect(names(filterNewGames(games, { ...EMPTY_NEW_GAMES_FILTER, name: " fact" }))).toEqual(["Factorio"]);
    expect(names(filterNewGames(games, { ...EMPTY_NEW_GAMES_FILTER, availability: "experimental" }))).toEqual(["Adult Game"]);
    expect(names(filterNewGames(games, { ...EMPTY_NEW_GAMES_FILTER, flag: "bundled" }))).toEqual(["Factorio"]);
    expect(names(filterNewGames(games, { ...EMPTY_NEW_GAMES_FILTER, flag: "adult" }))).toEqual(["Adult Game"]);
    expect(names(filterNewGames(games, { ...EMPTY_NEW_GAMES_FILTER, flag: "none" }))).toEqual(["Plain Game"]);
    expect(names(filterNewGames(games, { ...EMPTY_NEW_GAMES_FILTER, votes: "with" }))).toEqual(["Factorio"]);
    expect(names(filterNewGames(games, { ...EMPTY_NEW_GAMES_FILTER, votes: "without" }))).toEqual(["Adult Game", "Plain Game"]);
  });

  it("tells a deployed version behind the latest one, never against an unknown latest", () => {
    expect(hasVersionGap({ deployedVersion: "1.0.0", latestVersion: "1.1.0" })).toBe(true);
    expect(hasVersionGap({ deployedVersion: null, latestVersion: "1.1.0" })).toBe(true);
    expect(hasVersionGap({ deployedVersion: "1.0.0", latestVersion: "1.0.0" })).toBe(false);
    expect(hasVersionGap({ deployedVersion: "1.0.0", latestVersion: null })).toBe(false);
  });

  it("filters apworld updates on name, status and version gap", () => {
    const updates = [
      update("Behind", { latestVersion: "2.0.0", updateStatus: "update_available" }),
      update("Current"),
      update("Unchecked", { latestVersion: null, updateStatus: "unknown" }),
    ];

    expect(names(filterUpdates(updates, { ...EMPTY_UPDATES_FILTER, versions: "different" }))).toEqual(["Behind"]);
    expect(names(filterUpdates(updates, { ...EMPTY_UPDATES_FILTER, versions: "same" }))).toEqual(["Current", "Unchecked"]);
    expect(names(filterUpdates(updates, { ...EMPTY_UPDATES_FILTER, status: "unknown" }))).toEqual(["Unchecked"]);
    expect(names(filterUpdates(updates, { ...EMPTY_UPDATES_FILTER, name: "CUR" }))).toEqual(["Current"]);
  });

  it("filters stability changes on both availabilities and the lock", () => {
    const changes = [
      change("Up", { currentAvailability: "experimental", newAvailability: "available" }),
      change("Down", { availabilityLocked: true }),
    ];

    expect(names(filterChanges(changes, { ...EMPTY_CHANGES_FILTER, current: "experimental" }))).toEqual(["Up"]);
    expect(names(filterChanges(changes, { ...EMPTY_CHANGES_FILTER, next: "experimental" }))).toEqual(["Down"]);
    expect(names(filterChanges(changes, { ...EMPTY_CHANGES_FILTER, locked: "locked" }))).toEqual(["Down"]);
    expect(names(filterChanges(changes, { ...EMPTY_CHANGES_FILTER, locked: "unlocked" }))).toEqual(["Up"]);
  });

  it("filters the removed games by name", () => {
    expect(names(filterRemoved([{ gameId: "1", gameName: "Gone" }, { gameId: "2", gameName: "Other" }], "gon"))).toEqual(["Gone"]);
  });
});
