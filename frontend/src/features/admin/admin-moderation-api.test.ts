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
  reporter: null,
  comment: null,
  profile: null,
};

describe("buildReportsQuery", () => {
  it("omits 'any' filters and empty search by default", () => {
    const params = new URLSearchParams(buildReportsQuery(DEFAULT_REPORT_FILTERS));
    expect(params.get("status")).toBe("pending");
    expect(params.get("sort")).toBe("recent");
    expect(params.has("commentState")).toBe(false);
    expect(params.has("targetType")).toBe(false);
    expect(params.has("q")).toBe(false);
  });

  it("includes active filters and trims the search term", () => {
    const params = new URLSearchParams(
      buildReportsQuery({
        status: "resolved",
        commentState: "hidden",
        targetType: "comment",
        sort: "oldest",
        search: "  insulte  ",
      }),
    );
    expect(params.get("status")).toBe("resolved");
    expect(params.get("commentState")).toBe("hidden");
    expect(params.get("targetType")).toBe("comment");
    expect(params.get("sort")).toBe("oldest");
    expect(params.get("q")).toBe("insulte");
  });
});

describe("fetchModerationQueue", () => {
  it("sends the filters as query params and parses the response", async () => {
    let requestUrl = "";
    server.use(
      http.get(`${BASE}/admin/community/reports`, ({ request }) => {
        requestUrl = request.url;
        return HttpResponse.json({ data: [report], meta: { count: 3 } });
      }),
    );

    const result = await fetchModerationQueue({
      status: "all",
      commentState: "visible",
      targetType: "profile",
      sort: "oldest",
      search: "bob",
    });

    expect(result?.count).toBe(3);
    expect(result?.reports).toHaveLength(1);

    const params = new URL(requestUrl).searchParams;
    expect(params.get("status")).toBe("all");
    expect(params.get("commentState")).toBe("visible");
    expect(params.get("targetType")).toBe("profile");
    expect(params.get("sort")).toBe("oldest");
    expect(params.get("q")).toBe("bob");
  });

  it("returns null on error", async () => {
    server.use(http.get(`${BASE}/admin/community/reports`, () => HttpResponse.error()));
    expect(await fetchModerationQueue()).toBeNull();
  });
});
