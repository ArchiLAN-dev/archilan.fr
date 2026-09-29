import { DEFAULT_CONTRIBUTION_FILTERS } from "./admin-game-contributions-api";
import { DEFAULT_REPORT_FILTERS } from "./admin-moderation-api";
import {
  clearedContributionFilters,
  clearedReportFilters,
  contributionChips,
  contributionFiltersActive,
  contributionFiltersFromParams,
  contributionFiltersToParams,
  moderationTabFromParams,
  reportChips,
  reportFiltersActive,
  reportFiltersFromParams,
  reportFiltersToParams,
} from "./moderation-filters";

/**
 * Story 39.12. The moderation view lives in the page address: a reload, the back button or a shared link
 * gives the same tab, filters, sort and search. What narrows the list is listed, to offer a reset.
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

  test("what narrows the list, search included", () => {
    const filters = { ...DEFAULT_CONTRIBUTION_FILTERS, target: "listed" as const, search: "hk" };

    expect(contributionChips(filters).map((chip) => chip.label)).toEqual(["Jeux listés", "« hk »"]);
  });
});

describe("moderation tab", () => {
  test("reports by default, contributions when asked", () => {
    expect(moderationTabFromParams(new URLSearchParams())).toBe("reports");
    expect(moderationTabFromParams(new URLSearchParams("onglet=contributions"))).toBe("contributions");
    expect(moderationTabFromParams(new URLSearchParams("onglet=autre"))).toBe("reports");
  });
});

describe("reset and the status (story 39.13)", () => {
  test("the status is a filter like the others: it counts as active and the reset brings it back", () => {
    const resolved = { ...DEFAULT_REPORT_FILTERS, status: "resolved" as const, sort: "recent" as const };

    expect(reportFiltersActive(DEFAULT_REPORT_FILTERS)).toBe(false);
    expect(reportFiltersActive({ ...DEFAULT_REPORT_FILTERS, sort: "recent" })).toBe(false);
    expect(reportFiltersActive(resolved)).toBe(true);
    expect(clearedReportFilters({ ...resolved, targetType: "profile" })).toEqual({ ...DEFAULT_REPORT_FILTERS, sort: "recent" });
  });

  test("same for the contributions", () => {
    const approved = { ...DEFAULT_CONTRIBUTION_FILTERS, status: "approved" as const, sort: "oldest" as const };

    expect(contributionFiltersActive(DEFAULT_CONTRIBUTION_FILTERS)).toBe(false);
    expect(contributionFiltersActive(approved)).toBe(true);
    expect(clearedContributionFilters({ ...approved, search: "hk" })).toEqual({ ...DEFAULT_CONTRIBUTION_FILTERS, sort: "oldest" });
  });
});
