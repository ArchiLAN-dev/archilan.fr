import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import {
  approveContribution,
  buildContributionsQuery,
  DEFAULT_CONTRIBUTION_FILTERS,
  fetchContributionQueue,
  rejectContribution,
} from "./admin-game-contributions-api";

const BASE = TEST_API_BASE_URL;

const item = {
  id: "c1",
  status: "pending",
  createdAt: "2026-06-19T10:00:00+00:00",
  authorName: "Alice",
  message: null,
  target: "Hollow Knight",
  gameSlug: "hollow-knight",
  proposedSteps: [{ type: "apworld", title: "Étape", description: "", links: [] }],
  currentSteps: [],
};

describe("buildContributionsQuery", () => {
  it("omits 'any' target and empty search by default", () => {
    const params = new URLSearchParams(buildContributionsQuery(DEFAULT_CONTRIBUTION_FILTERS));
    expect(params.get("status")).toBe("pending");
    expect(params.get("sort")).toBe("recent");
    expect(params.has("target")).toBe(false);
    expect(params.has("q")).toBe(false);
  });

  it("includes active filters and trims the search term", () => {
    const params = new URLSearchParams(
      buildContributionsQuery({ status: "all", target: "unlisted", sort: "oldest", search: "  inconnu  " }),
    );
    expect(params.get("status")).toBe("all");
    expect(params.get("target")).toBe("unlisted");
    expect(params.get("sort")).toBe("oldest");
    expect(params.get("q")).toBe("inconnu");
  });
});

describe("fetchContributionQueue", () => {
  it("sends the filters as query params and parses items + count", async () => {
    let requestUrl = "";
    server.use(
      http.get(`${BASE}/admin/game-contributions`, ({ request }) => {
        requestUrl = request.url;
        return HttpResponse.json({ data: [item], meta: { count: 5 } });
      }),
    );

    const result = await fetchContributionQueue({
      status: "approved",
      target: "listed",
      sort: "oldest",
      search: "hollow",
    });

    expect(result.count).toBe(5);
    expect(result.items).toHaveLength(1);
    expect(result.items[0].target).toBe("Hollow Knight");

    const params = new URL(requestUrl).searchParams;
    expect(params.get("status")).toBe("approved");
    expect(params.get("target")).toBe("listed");
    expect(params.get("sort")).toBe("oldest");
    expect(params.get("q")).toBe("hollow");
  });

  it("falls back to the item count when meta is absent", async () => {
    server.use(http.get(`${BASE}/admin/game-contributions`, () => HttpResponse.json({ data: [item] })));
    const result = await fetchContributionQueue();
    expect(result.items).toHaveLength(1);
    expect(result.count).toBe(1);
  });

  it("returns an empty queue on malformed item", async () => {
    server.use(http.get(`${BASE}/admin/game-contributions`, () => HttpResponse.json({ data: [{ id: "c1" }] })));
    const result = await fetchContributionQueue();
    expect(result.items).toHaveLength(0);
    expect(result.count).toBe(0);
  });

  it("returns an empty queue on error", async () => {
    server.use(http.get(`${BASE}/admin/game-contributions`, () => HttpResponse.error()));
    const result = await fetchContributionQueue();
    expect(result.items).toHaveLength(0);
    expect(result.count).toBe(0);
  });
});

describe("approve/reject", () => {
  it("approve returns true on ok", async () => {
    server.use(http.post(`${BASE}/admin/game-contributions/c1/approve`, () => new HttpResponse(null, { status: 200 })));
    expect(await approveContribution("c1")).toBe(true);
  });

  it("reject returns false on conflict", async () => {
    server.use(http.post(`${BASE}/admin/game-contributions/c1/reject`, () => new HttpResponse(null, { status: 409 })));
    expect(await rejectContribution("c1", "raison")).toBe(false);
  });
});
