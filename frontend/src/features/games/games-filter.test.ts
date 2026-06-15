import { filterAndSortGames, isOwned, type CatalogFilters } from "./games-filter";
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
  };
}

const base: CatalogFilters = { query: "", availability: "all", ownedOnly: false, sort: "name-asc" };

const hollow = game({ name: "Hollow Knight", steamAppId: 367520 });
const celeste = game({ name: "Celeste", steamAppId: 504230 });
const zelda = game({ name: "Zelda", availability: "experimental", description: "adventure" });
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

  it("filters to owned only and lists owned first when coupled", () => {
    const owned = new Set([367520]);
    expect(filterAndSortGames(games, { ...base, ownedOnly: true }, owned).map((g) => g.name)).toEqual([
      "Hollow Knight",
    ]);
    // owned-first ordering when a coupling is active (not filtered)
    expect(filterAndSortGames(games, base, owned).map((g) => g.name)).toEqual([
      "Hollow Knight",
      "Celeste",
      "Zelda",
    ]);
  });
});

describe("isOwned", () => {
  it("is true only when steamAppId is in the owned set", () => {
    expect(isOwned(hollow, new Set([367520]))).toBe(true);
    expect(isOwned(celeste, new Set([367520]))).toBe(false);
    expect(isOwned(zelda, new Set([367520]))).toBe(false);
  });
});
