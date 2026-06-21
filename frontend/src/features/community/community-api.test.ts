import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchLeaderboard, fetchCommunityStats } from "./community-api";

const BASE = TEST_API_BASE_URL;

describe("fetchLeaderboard", () => {
  const validResponse = {
    data: [{ rank: 1, slug: "jean", displayName: "Jean", avatarUrl: null, value: 10, unit: "objectifs" }],
    meta: { axis: "goals", page: 1, total: 1 },
  };

  it("returns leaderboard on success", async () => {
    server.use(
      http.get(`${BASE}/leaderboard`, () => HttpResponse.json(validResponse)),
    );
    const result = await fetchLeaderboard("goals", 10);
    expect(result).not.toBeNull();
    expect(result?.data[0].slug).toBe("jean");
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/leaderboard`, () => HttpResponse.error()));
    expect(await fetchLeaderboard("goals", 10)).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(http.get(`${BASE}/leaderboard`, () => HttpResponse.json({ wrong: true })));
    expect(await fetchLeaderboard("goals", 10)).toBeNull();
  });
});

describe("fetchCommunityStats", () => {
  const validResponse = {
    data: { totalFinishedSessions: 5, totalChecksDone: 100, totalGoalsReached: 3 },
  };

  it("returns stats on success", async () => {
    server.use(
      http.get(`${BASE}/community/stats`, () => HttpResponse.json(validResponse)),
    );
    const result = await fetchCommunityStats();
    expect(result).not.toBeNull();
    expect(result?.totalFinishedSessions).toBe(5);
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/community/stats`, () => HttpResponse.error()));
    expect(await fetchCommunityStats()).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(http.get(`${BASE}/community/stats`, () => HttpResponse.json({ data: "bad" })));
    expect(await fetchCommunityStats()).toBeNull();
  });
});
