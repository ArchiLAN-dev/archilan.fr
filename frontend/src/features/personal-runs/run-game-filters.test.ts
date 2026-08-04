import {
  filterRunGames,
  orderRunGames,
  runFilterOptions,
  type PlayerGameSources,
  type RunGameFilters,
} from "./run-game-filters";
import type { GameSelectionGame } from "./personal-runs-api";

function game(overrides: Partial<GameSelectionGame> & { name: string }): GameSelectionGame {
  return {
    id: overrides.id ?? overrides.name.toLowerCase(),
    name: overrides.name,
    slug: overrides.slug ?? overrides.name.toLowerCase().replace(/\s+/g, "-"),
    description: overrides.description ?? "",
    availability: overrides.availability ?? "available",
    isApworldReady: overrides.isApworldReady ?? true,
    defaultYaml: overrides.defaultYaml ?? null,
    optionTypes: overrides.optionTypes ?? null,
    locationNames: overrides.locationNames ?? null,
    coverImageUrl: overrides.coverImageUrl ?? null,
    coverImageAlt: overrides.coverImageAlt ?? "",
    platforms: overrides.platforms ?? [],
    steamAppId: overrides.steamAppId ?? null,
  };
}

const hollow = game({ name: "Hollow Knight", steamAppId: 367520, platforms: ["PC"] });
const luigi = game({ name: "Luigi", description: "manoir hanté", platforms: ["GameCube"] });
const zelda = game({ name: "Zelda", platforms: ["Nintendo 64"] });
const games = [hollow, luigi, zelda];

const noSources: PlayerGameSources = {
  steamAppIds: new Set(),
  ownedGameIds: new Set(),
  plannedGameIds: new Set(),
};

const base: RunGameFilters = { query: "", list: "all", recentOnly: false, categories: [] };

const names = (gs: GameSelectionGame[]) => gs.map((g) => g.name);

describe("filterRunGames - the owned filter unions both sources (story 28.15)", () => {
  it("recognises a game marked by hand even with no steamAppId", () => {
    // The debt story 28.13 left here: this page knew the Steam coupling alone, so a GameCube
    // title showed up under /jeux and not in the run picker.
    const sources = { ...noSources, ownedGameIds: new Set(["luigi"]) };

    expect(names(filterRunGames(games, { ...base, list: "owned" }, sources, new Set()))).toEqual([
      "Luigi",
    ]);
  });

  it("still recognises a coupled Steam game with nothing marked by hand", () => {
    const sources = { ...noSources, steamAppIds: new Set([367520]) };

    expect(names(filterRunGames(games, { ...base, list: "owned" }, sources, new Set()))).toEqual([
      "Hollow Knight",
    ]);
  });

  it("takes both sources at once", () => {
    const sources = {
      ...noSources,
      ownedGameIds: new Set(["luigi"]),
      steamAppIds: new Set([367520]),
    };

    expect(names(filterRunGames(games, { ...base, list: "owned" }, sources, new Set()))).toEqual([
      "Hollow Knight",
      "Luigi",
    ]);
  });
});

describe("filterRunGames - the two lists stay apart", () => {
  it("filters to the planned list alone", () => {
    const sources = { ...noSources, plannedGameIds: new Set(["zelda"]) };

    expect(names(filterRunGames(games, { ...base, list: "planned" }, sources, new Set()))).toEqual([
      "Zelda",
    ]);
  });

  it("never lets the planned list satisfy the owned filter", () => {
    // Wanting a game must not make the picker claim you have it.
    const sources = { ...noSources, plannedGameIds: new Set(["zelda"]) };

    expect(filterRunGames(games, { ...base, list: "owned" }, sources, new Set())).toEqual([]);
  });

  it("never lets an owned source satisfy the planned filter", () => {
    const sources = {
      ...noSources,
      ownedGameIds: new Set(["luigi"]),
      steamAppIds: new Set([367520]),
    };

    expect(filterRunGames(games, { ...base, list: "planned" }, sources, new Set())).toEqual([]);
  });

  it("keeps a game sitting on both lists under either filter", () => {
    const sources = {
      ...noSources,
      ownedGameIds: new Set(["zelda"]),
      plannedGameIds: new Set(["zelda"]),
    };

    expect(names(filterRunGames(games, { ...base, list: "owned" }, sources, new Set()))).toEqual([
      "Zelda",
    ]);
    expect(names(filterRunGames(games, { ...base, list: "planned" }, sources, new Set()))).toEqual([
      "Zelda",
    ]);
  });
});

describe("filterRunGames - the other axes", () => {
  it("combines a list filter with 'récemment joués' rather than replacing it", () => {
    // AC5: recency is a different axis, so the two narrow together.
    const sources = { ...noSources, plannedGameIds: new Set(["zelda", "luigi"]) };
    const filters = { ...base, list: "planned" as const, recentOnly: true };

    expect(names(filterRunGames(games, filters, sources, new Set(["luigi"])))).toEqual(["Luigi"]);
  });

  it("combines a list filter with a category", () => {
    const sources = { ...noSources, ownedGameIds: new Set(["luigi", "zelda"]) };
    const filters = { ...base, list: "owned" as const, categories: ["Nintendo 64"] };

    expect(names(filterRunGames(games, filters, sources, new Set()))).toEqual(["Zelda"]);
  });

  it("searches name and description, case- and whitespace-insensitively", () => {
    expect(names(filterRunGames(games, { ...base, query: "  HANTÉ " }, noSources, new Set()))).toEqual([
      "Luigi",
    ]);
  });

  it("keeps everything when nothing is filtered", () => {
    expect(filterRunGames(games, base, noSources, new Set())).toHaveLength(3);
  });
});

describe("orderRunGames - recently played bubble to the top", () => {
  const rank = new Map([
    ["zelda", 0],
    ["hollow knight", 1],
  ]);

  it("pins recently played games in recency order", () => {
    expect(names(orderRunGames(games, rank, new Set(), ""))).toEqual([
      "Zelda",
      "Hollow Knight",
      "Luigi",
    ]);
  });

  it("does not pin a game already in the selection", () => {
    // It lives under "Ma sélection"; pinning it again would offer what is already taken.
    expect(names(orderRunGames(games, rank, new Set(["zelda"]), ""))).toEqual([
      "Hollow Knight",
      "Luigi",
      "Zelda",
    ]);
  });

  it("drops the pinned block during a search", () => {
    // Once the player is naming what they want, a pinned block only pushes the answer down.
    expect(names(orderRunGames(games, rank, new Set(), "zel"))).toEqual(names(games));
  });

  it("leaves the order alone when nothing was recently played", () => {
    expect(orderRunGames(games, new Map(), new Set(), "")).toEqual(games);
  });
});

describe("runFilterOptions - what the picker offers", () => {
  const all = { hasRecent: true, hasOwnership: true, hasPlanned: true };

  it("offers every filter with a non-empty source", () => {
    expect(runFilterOptions(all, base).map((o) => o.label)).toEqual([
      "Récemment joués",
      "Mes jeux",
      "À essayer",
    ]);
  });

  it("hides a filter whose source is empty", () => {
    const sources = { hasRecent: false, hasOwnership: false, hasPlanned: true };

    expect(runFilterOptions(sources, base).map((o) => o.label)).toEqual(["À essayer"]);
  });

  it("stops offering the active list and keeps offering the other - they replace, not stack", () => {
    const offered = runFilterOptions(all, { ...base, list: "owned" }).map((o) => o.label);

    expect(offered).not.toContain("Mes jeux");
    expect(offered).toContain("À essayer");
  });

  it("keeps offering the list filters while 'récemment joués' is active", () => {
    const offered = runFilterOptions(all, { ...base, recentOnly: true }).map((o) => o.label);

    expect(offered).toEqual(["Mes jeux", "À essayer"]);
  });
});
