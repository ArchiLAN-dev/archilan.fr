import { DEFAULT_FILTERS, filtersToQueryString, readFiltersFromParams } from "./catalog-url-filters";

describe("filtersToQueryString", () => {
  it("returns an empty string when nothing is filtered", () => {
    // A pristine /jeux must never grow a trailing "?": the page is served from the ISR cache under
    // a canonical URL (story 28.12, AC3).
    expect(filtersToQueryString(DEFAULT_FILTERS)).toBe("");
  });

  it("omits every filter left at its default", () => {
    const qs = filtersToQueryString({ ...DEFAULT_FILTERS, query: "metroid" });

    expect(qs).toBe("q=metroid");
  });

  it("writes each filter it carries", () => {
    const qs = new URLSearchParams(
      filtersToQueryString({
        availability: "experimental",
        categories: ["GameCube", "PC"],
        ownedOnly: true,
        query: "luigi",
        sort: "name-desc",
      }),
    );

    expect(qs.get("q")).toBe("luigi");
    expect(qs.get("dispo")).toBe("experimental");
    expect(qs.get("mes-jeux")).toBe("1");
    expect(qs.get("tri")).toBe("name-desc");
    expect(qs.getAll("cat")).toEqual(["GameCube", "PC"]);
  });

  it("trims the query and drops it when only whitespace", () => {
    expect(filtersToQueryString({ ...DEFAULT_FILTERS, query: "   " })).toBe("");
    expect(filtersToQueryString({ ...DEFAULT_FILTERS, query: "  zelda " })).toBe("q=zelda");
  });
});

describe("readFiltersFromParams", () => {
  it("round-trips a filter state through the URL", () => {
    const filters = {
      availability: "available" as const,
      categories: ["Switch"],
      ownedOnly: true,
      query: "mario",
      sort: "name-desc" as const,
    };

    expect(readFiltersFromParams(new URLSearchParams(filtersToQueryString(filters)))).toEqual(filters);
  });

  it("falls back to defaults on an empty URL", () => {
    expect(readFiltersFromParams(new URLSearchParams(""))).toEqual(DEFAULT_FILTERS);
  });

  it("ignores forged values rather than producing an impossible state", () => {
    const filters = readFiltersFromParams(new URLSearchParams("dispo=nope&tri=random&mes-jeux=yes"));

    expect(filters.availability).toBe("all");
    expect(filters.sort).toBe("name-asc");
    // Anything but "1" reads as off - no truthiness games on user-supplied input.
    expect(filters.ownedOnly).toBe(false);
  });

  it("drops empty category values", () => {
    expect(readFiltersFromParams(new URLSearchParams("cat=&cat=PC")).categories).toEqual(["PC"]);
  });
});
