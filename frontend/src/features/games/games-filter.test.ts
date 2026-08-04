import {
  allCategories,
  categoriesOf,
  filterAndSortGames,
  isOwned,
  type CatalogFilters,
} from "./games-filter";
import type { PublicGame } from "./public-games-api";

function game(overrides: Partial<PublicGame> & { name: string }): PublicGame {
  return {
    id: overrides.id ?? overrides.name.toLowerCase(),
    name: overrides.name,
    slug: overrides.slug ?? overrides.name.toLowerCase().replace(/\s+/g, "-"),
    description: overrides.description ?? "",
    coverImageUrl: overrides.coverImageUrl ?? null,
    coverImageAlt: overrides.coverImageAlt ?? "",
    availability: overrides.availability ?? "available",
    supportedEventTypes: overrides.supportedEventTypes ?? [],
    steamAppId: overrides.steamAppId ?? null,
    platforms: overrides.platforms ?? [],
  };
}

const base: CatalogFilters = {
  query: "",
  availability: "all",
  list: "all",
  sort: "name-asc",
  categories: [],
};

const hollow = game({ name: "Hollow Knight", steamAppId: 367520, platforms: ["PC"] });
const celeste = game({ name: "Celeste", steamAppId: 504230, platforms: ["PC", "Switch"] });
const zelda = game({
  name: "Zelda",
  availability: "experimental",
  description: "adventure",
  platforms: ["Nintendo 64"],
});
const games = [hollow, celeste, zelda];

describe("filterAndSortGames", () => {
  it("sorts by name ascending by default", () => {
    expect(filterAndSortGames(games, base, new Set()).map((g) => g.name)).toEqual([
      "Celeste",
      "Hollow Knight",
      "Zelda",
    ]);
  });

  it("sorts by name descending", () => {
    expect(
      filterAndSortGames(games, { ...base, sort: "name-desc" }, new Set()).map((g) => g.name),
    ).toEqual(["Zelda", "Hollow Knight", "Celeste"]);
  });

  it("filters by search over name and description", () => {
    expect(filterAndSortGames(games, { ...base, query: "advent" }, new Set()).map((g) => g.name)).toEqual([
      "Zelda",
    ]);
  });

  it("filters by availability", () => {
    expect(
      filterAndSortGames(games, { ...base, availability: "experimental" }, new Set()).map((g) => g.name),
    ).toEqual(["Zelda"]);
  });

  it("filters to owned only", () => {
    const owned = new Set([367520]);
    expect(filterAndSortGames(games, { ...base, list: "owned" }, owned).map((g) => g.name)).toEqual([
      "Hollow Knight",
    ]);
  });

  it("keeps the classic name sort even when coupled (no owned-first)", () => {
    const owned = new Set([367520]);
    expect(filterAndSortGames(games, base, owned).map((g) => g.name)).toEqual([
      "Celeste",
      "Hollow Knight",
      "Zelda",
    ]);
  });
});

describe("list filtering - two independent lists (story 28.14)", () => {
  it("filters to the planned list, which owes nothing to Steam", () => {
    expect(
      filterAndSortGames(games, { ...base, list: "planned" }, new Set(), new Set(), new Set(["zelda"])).map(
        (g) => g.name,
      ),
    ).toEqual(["Zelda"]);
  });

  it("does not let the planned list leak into the owned filter", () => {
    // Wanting a game must never make the catalog claim you have it.
    expect(
      filterAndSortGames(games, { ...base, list: "owned" }, new Set(), new Set(), new Set(["zelda"])),
    ).toEqual([]);
  });

  it("does not let the owned lists leak into the planned filter", () => {
    expect(
      filterAndSortGames(games, { ...base, list: "planned" }, new Set([367520]), new Set(["celeste"])),
    ).toEqual([]);
  });

  it("keeps a game that is on both lists", () => {
    const both = filterAndSortGames(
      games,
      { ...base, list: "planned" },
      new Set(),
      new Set(["celeste"]),
      new Set(["celeste"]),
    );

    expect(both.map((g) => g.name)).toEqual(["Celeste"]);
  });
});

describe("category filtering", () => {
  it("keeps games matching any selected category", () => {
    expect(
      filterAndSortGames(games, { ...base, categories: ["Nintendo 64"] }, new Set()).map((g) => g.name),
    ).toEqual(["Zelda"]);
  });

  it("treats Steam as a category facet", () => {
    expect(
      filterAndSortGames(games, { ...base, categories: ["Steam"] }, new Set()).map((g) => g.name),
    ).toEqual(["Celeste", "Hollow Knight"]);
  });

  it("ORs multiple selected categories", () => {
    expect(
      filterAndSortGames(games, { ...base, categories: ["Switch", "Nintendo 64"] }, new Set()).map((g) => g.name),
    ).toEqual(["Celeste", "Zelda"]);
  });
});

describe("categoriesOf / allCategories", () => {
  it("includes Steam when the game has a steamAppId", () => {
    expect(categoriesOf(hollow)).toEqual(["PC", "Steam"]);
    expect(categoriesOf(zelda)).toEqual(["Nintendo 64"]);
  });

  it("returns the sorted distinct union for the chip list", () => {
    expect(allCategories(games)).toEqual(["Nintendo 64", "PC", "Steam", "Switch"]);
  });
});

describe("isOwned", () => {
  it("is true only when steamAppId is in the owned set", () => {
    expect(isOwned(hollow, new Set([367520]))).toBe(true);
    expect(isOwned(celeste, new Set([367520]))).toBe(false);
    expect(isOwned(zelda, new Set([367520]))).toBe(false);
  });
});

describe("isOwned - two sources (story 28.13)", () => {
  const gameCube = { id: "g1", platforms: ["GameCube"], steamAppId: null };
  const onSteam = { id: "g2", platforms: ["PC"], steamAppId: 42 };

  it("recognises a game marked on ArchiLAN even with no steamAppId", () => {
    // The whole point: a console-era game can never match a coupled Steam library.
    expect(isOwned(gameCube, new Set(), new Set(["g1"]))).toBe(true);
  });

  it("still recognises a coupled Steam game with nothing marked by hand", () => {
    expect(isOwned(onSteam, new Set([42]), new Set())).toBe(true);
  });

  it("is false when neither source claims it", () => {
    expect(isOwned(onSteam, new Set([99]), new Set(["other"]))).toBe(false);
    expect(isOwned(gameCube, new Set([42]), new Set())).toBe(false);
  });

  it("keeps the manual mark when the Steam library does not contain the game", () => {
    // Re-coupling Steam must never wipe what the player claimed by hand.
    expect(isOwned(onSteam, new Set(), new Set(["g2"]))).toBe(true);
  });
});
