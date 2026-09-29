import { DEFAULT_CONTRIBUTION_FILTERS } from "./admin-game-contributions-api";
import { DEFAULT_REPORT_FILTERS } from "./admin-moderation-api";
import {
  contributionChips,
  contributionFiltersFromParams,
  contributionFiltersToParams,
  moderationTabFromParams,
  reportChips,
  reportFiltersFromParams,
  reportFiltersToParams,
  withoutContributionChip,
  withoutReportChip,
} from "./moderation-filters";

/**
 * Story 39.12. The moderation view lives in the page address: a reload, the back button or a shared link
 * gives the same tab, filters, sort and search. Active filters read as removable chips.
 */
describe("report filters and the URL", () => {
  test("the defaults make a clean address", () => {
    expect(reportFiltersToParams(DEFAULT_REPORT_FILTERS).toString()).toBe("");
    expect(reportFiltersFromParams(new URLSearchParams())).toEqual(DEFAULT_REPORT_FILTERS);
  });

  test("every filter survives the round trip", () => {
    const filters = {
      status: "resolved" as const,
      commentState: "hidden" as const,
      targetType: "comment" as const,
      problem: "harassment" as const,
      uncategorized: true,
      sort: "recent" as const,
      search: "insulte",
    };

    const params = reportFiltersToParams(filters);

    expect(params.toString()).toBe("statut=resolved&cible=comment&contenu=harassment&commentaire=hidden&noncat=1&tri=recent&q=insulte");
    expect(reportFiltersFromParams(params)).toEqual(filters);
  });

  test("unknown values fall back to the defaults", () => {
    expect(reportFiltersFromParams(new URLSearchParams("statut=nope&cible=x&contenu=y&tri=z&noncat=0"))).toEqual(DEFAULT_REPORT_FILTERS);
  });
});

describe("report chips", () => {
  test("only what narrows the list shows, search included", () => {
    expect(reportChips(DEFAULT_REPORT_FILTERS)).toEqual([]);

    const chips = reportChips({ ...DEFAULT_REPORT_FILTERS, targetType: "profile", problem: "hate", commentState: "visible", uncategorized: true, search: "spam" });

    expect(chips.map((chip) => chip.label)).toEqual(["Profils", "Haine", "Commentaires visibles", "Non catégorisés", "« spam »"]);
  });

  test("removing a chip resets that filter only", () => {
    const filters = { ...DEFAULT_REPORT_FILTERS, targetType: "profile" as const, problem: "hate" as const };

    expect(withoutReportChip(filters, "targetType")).toEqual({ ...filters, targetType: "any" });
    expect(withoutReportChip({ ...filters, search: "x" }, "search")).toEqual(filters);
  });
});

describe("contribution filters", () => {
  test("round trip, defaults omitted, unknown values ignored", () => {
    expect(contributionFiltersToParams(DEFAULT_CONTRIBUTION_FILTERS).toString()).toBe("");

    const filters = { status: "rejected" as const, target: "unlisted" as const, sort: "oldest" as const, search: "celeste" };
    const params = contributionFiltersToParams(filters);

    expect(params.toString()).toBe("statut=rejected&cible=unlisted&tri=oldest&q=celeste");
    expect(contributionFiltersFromParams(params)).toEqual(filters);
    expect(contributionFiltersFromParams(new URLSearchParams("statut=resolved&cible=comment"))).toEqual(DEFAULT_CONTRIBUTION_FILTERS);
  });

  test("chips and their removal", () => {
    const filters = { ...DEFAULT_CONTRIBUTION_FILTERS, target: "listed" as const, search: "hk" };

    expect(contributionChips(filters).map((chip) => chip.label)).toEqual(["Jeux listés", "« hk »"]);
    expect(withoutContributionChip(filters, "target")).toEqual({ ...filters, target: "any" });
  });
});

describe("moderation tab", () => {
  test("reports by default, contributions when asked", () => {
    expect(moderationTabFromParams(new URLSearchParams())).toBe("reports");
    expect(moderationTabFromParams(new URLSearchParams("onglet=contributions"))).toBe("contributions");
    expect(moderationTabFromParams(new URLSearchParams("onglet=autre"))).toBe("reports");
  });
});
