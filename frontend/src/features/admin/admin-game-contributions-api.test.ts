import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { approveContribution, fetchContributionQueue, rejectContribution } from "./admin-game-contributions-api";

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

describe("fetchContributionQueue", () => {
  it("returns the queue on success", async () => {
    server.use(http.get(`${BASE}/admin/game-contributions`, () => HttpResponse.json({ data: [item] })));
    const result = await fetchContributionQueue();
    expect(result).toHaveLength(1);
    expect(result[0].target).toBe("Hollow Knight");
  });

  it("returns empty on malformed item", async () => {
    server.use(http.get(`${BASE}/admin/game-contributions`, () => HttpResponse.json({ data: [{ id: "c1" }] })));
    expect(await fetchContributionQueue()).toHaveLength(0);
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
