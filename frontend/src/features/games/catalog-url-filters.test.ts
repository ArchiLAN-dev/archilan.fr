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
        list: "owned",
        query: "luigi",
        sort: "name-desc",
      }),
    );

    expect(qs.get("q")).toBe("luigi");
    expect(qs.get("dispo")).toBe("experimental");
    expect(qs.get("liste")).toBe("mes-jeux");
    expect(qs.get("tri")).toBe("name-desc");
    expect(qs.getAll("cat")).toEqual(["GameCube", "PC"]);
  });

  it("writes the planned list under its own slug (story 28.14)", () => {
    expect(filtersToQueryString({ ...DEFAULT_FILTERS, list: "planned" })).toBe("liste=a-essayer");
  });

  it("never writes the legacy mes-jeux flag any more", () => {
    // Two spellings of one state is what 28.14 set out to stop; `mes-jeux=1` stays readable only.
    expect(filtersToQueryString({ ...DEFAULT_FILTERS, list: "owned" })).not.toContain("mes-jeux=1");
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
      list: "planned" as const,
      query: "mario",
      sort: "name-desc" as const,
    };

    expect(readFiltersFromParams(new URLSearchParams(filtersToQueryString(filters)))).toEqual(filters);
  });

  it("still honours the mes-jeux=1 flag story 28.12 used to write", () => {
    // A URL shared before 28.14 must keep landing on the same list.
    expect(readFiltersFromParams(new URLSearchParams("mes-jeux=1")).list).toBe("owned");
  });

  it("lets the explicit liste parameter win over the legacy flag", () => {
    expect(readFiltersFromParams(new URLSearchParams("liste=a-essayer&mes-jeux=1")).list).toBe("planned");
  });

  it("falls back to defaults on an empty URL", () => {
    expect(readFiltersFromParams(new URLSearchParams(""))).toEqual(DEFAULT_FILTERS);
  });

  it("ignores forged values rather than producing an impossible state", () => {
    const filters = readFiltersFromParams(
      new URLSearchParams("dispo=nope&tri=random&mes-jeux=yes&liste=wishlist"),
    );

    expect(filters.availability).toBe("all");
    expect(filters.sort).toBe("name-asc");
    // Anything but "1" reads as off, and an unknown list slug is no list at all - no truthiness
    // games on user-supplied input.
    expect(filters.list).toBe("all");
  });

  it("drops empty category values", () => {
    expect(readFiltersFromParams(new URLSearchParams("cat=&cat=PC")).categories).toEqual(["PC"]);
  });
});
