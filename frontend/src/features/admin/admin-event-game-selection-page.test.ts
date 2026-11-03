import { CATALOGUE_PAGE_SIZE, isDraftDirty, paginate } from "./admin-event-game-selection-page";

describe("event catalogue paging (story 3.16)", () => {
  const games = Array.from({ length: 820 }, (_, i) => ({ id: `g${i}` }));

  it("shows one page of the catalogue, never the 800 games at once", () => {
    const { pageItems, safePage, totalPages } = paginate(games, 1, CATALOGUE_PAGE_SIZE);
    expect(pageItems).toHaveLength(50);
    expect(pageItems[0]?.id).toBe("g0");
    expect(safePage).toBe(1);
    expect(totalPages).toBe(17);
    expect(paginate(games, 17, CATALOGUE_PAGE_SIZE).pageItems).toHaveLength(20);
  });

  it("falls back to the last page when a filter shrinks the list", () => {
    const { safePage, pageItems } = paginate(games.slice(0, 60), 9, CATALOGUE_PAGE_SIZE);
    expect(safePage).toBe(2);
    expect(pageItems).toHaveLength(10);
    expect(paginate([], 3, CATALOGUE_PAGE_SIZE)).toEqual({ pageItems: [], safePage: 1, totalPages: 1 });
  });
});

describe("unsaved changes (story 3.16)", () => {
  const saved = { enabled: true, maxGames: 3, gameIds: new Set(["a", "b"]) };

  it("is clean when the draft matches what was saved, whatever the order", () => {
    expect(isDraftDirty(saved, { enabled: true, maxGames: 3, gameIds: new Set(["b", "a"]) })).toBe(false);
  });

  it("is dirty on any change of games, limit or switch", () => {
    expect(isDraftDirty(saved, { ...saved, gameIds: new Set(["a"]) })).toBe(true);
    expect(isDraftDirty(saved, { ...saved, gameIds: new Set(["a", "c"]) })).toBe(true);
    expect(isDraftDirty(saved, { ...saved, maxGames: null })).toBe(true);
    expect(isDraftDirty(saved, { ...saved, enabled: false })).toBe(true);
  });
});
