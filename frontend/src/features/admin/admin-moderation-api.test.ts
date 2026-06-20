import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { buildReportsQuery, DEFAULT_REPORT_FILTERS, fetchModerationQueue } from "./admin-moderation-api";

const BASE = TEST_API_BASE_URL;

const report = {
  id: "r1",
  targetType: "comment",
  targetId: "c1",
  reason: "spam",
  createdAt: "2026-01-01T10:00:00+00:00",
  category: "comment",
  problem: "spam",
  note: null,
  severity: 2,
  uncategorized: false,
  reporter: null,
  comment: null,
  profile: null,
};

describe("buildReportsQuery", () => {
  it("omits 'any' filters and empty search by default", () => {
    const params = new URLSearchParams(buildReportsQuery(DEFAULT_REPORT_FILTERS));
    expect(params.get("status")).toBe("pending");
    expect(params.get("sort")).toBe("severity");
    expect(params.has("commentState")).toBe(false);
    expect(params.has("targetType")).toBe(false);
    expect(params.has("problem")).toBe(false);
    expect(params.has("uncategorized")).toBe(false);
    expect(params.has("q")).toBe(false);
  });

  it("includes active filters and trims the search term", () => {
    const params = new URLSearchParams(
      buildReportsQuery({
        status: "resolved",
        commentState: "hidden",
        targetType: "comment",
        problem: "nudity",
        uncategorized: true,
        sort: "oldest",
        search: "  insulte  ",
      }),
    );
    expect(params.get("status")).toBe("resolved");
    expect(params.get("commentState")).toBe("hidden");
    expect(params.get("targetType")).toBe("comment");
    expect(params.get("problem")).toBe("nudity");
    expect(params.get("uncategorized")).toBe("1");
    expect(params.get("sort")).toBe("oldest");
    expect(params.get("q")).toBe("insulte");
  });
});

describe("fetchModerationQueue", () => {
  it("sends the filters as query params and parses reports + flagged accounts", async () => {
    let requestUrl = "";
    const flagged = { userId: "u1", slug: "bob", displayName: "Bob", avatarUrl: null, score: 20, reportCount: 2 };
    server.use(
      http.get(`${BASE}/admin/community/reports`, ({ request }) => {
        requestUrl = request.url;
        return HttpResponse.json({ data: [report], meta: { count: 3, threshold: 10, flagged: [flagged] } });
      }),
    );

    const result = await fetchModerationQueue({
      status: "all",
      commentState: "visible",
      targetType: "profile",
      problem: "spam",
      uncategorized: false,
      sort: "severity",
      search: "bob",
    });

    expect(result?.count).toBe(3);
    expect(result?.threshold).toBe(10);
    expect(result?.flagged).toHaveLength(1);
    expect(result?.flagged[0].slug).toBe("bob");
    expect(result?.reports).toHaveLength(1);
    expect(result?.reports[0].severity).toBe(2);

    const params = new URL(requestUrl).searchParams;
    expect(params.get("status")).toBe("all");
    expect(params.get("commentState")).toBe("visible");
    expect(params.get("targetType")).toBe("profile");
    expect(params.get("problem")).toBe("spam");
    expect(params.get("sort")).toBe("severity");
    expect(params.get("q")).toBe("bob");
  });

  it("returns null on error", async () => {
    server.use(http.get(`${BASE}/admin/community/reports`, () => HttpResponse.error()));
    expect(await fetchModerationQueue()).toBeNull();
  });
});
