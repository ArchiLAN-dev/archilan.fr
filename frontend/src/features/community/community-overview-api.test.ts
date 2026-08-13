import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchCommunityOverview } from "./community-overview-api";

const BASE = TEST_API_BASE_URL;

describe("fetchCommunityOverview", () => {
  const validResponse = {
    data: {
      memberCount: 42,
      playingNow: [{ slug: "jean", displayName: "Jean", avatarUrl: null, game: "Hollow Knight" }],
      recentAchievements: [
        {
          achievementKey: "first_run",
          name: "Première run",
          imageUrl: null,
          unlockedAt: "2026-08-01T10:00:00+00:00",
          slug: "jean",
          displayName: "Jean",
          avatarUrl: null,
        },
      ],
    },
  };

  it("returns the overview on success", async () => {
    server.use(http.get(`${BASE}/community/overview`, () => HttpResponse.json(validResponse)));

    const result = await fetchCommunityOverview();

    expect(result).not.toBeNull();
    expect(result?.memberCount).toBe(42);
    expect(result?.playingNow[0].game).toBe("Hollow Knight");
    expect(result?.recentAchievements[0].achievementKey).toBe("first_run");
  });

  it("accepts a withheld game (null) for a private run", async () => {
    server.use(
      http.get(`${BASE}/community/overview`, () =>
        HttpResponse.json({
          data: {
            ...validResponse.data,
            playingNow: [{ slug: "jean", displayName: null, avatarUrl: null, game: null }],
          },
        }),
      ),
    );

    const result = await fetchCommunityOverview();

    expect(result?.playingNow[0].game).toBeNull();
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/community/overview`, () => HttpResponse.error()));

    expect(await fetchCommunityOverview()).toBeNull();
  });

  it("returns null when a row fails the type guard", async () => {
    server.use(
      http.get(`${BASE}/community/overview`, () =>
        HttpResponse.json({
          data: { memberCount: 1, playingNow: [{ slug: 42 }], recentAchievements: [] },
        }),
      ),
    );

    expect(await fetchCommunityOverview()).toBeNull();
  });

  it("returns null when the payload shape is wrong", async () => {
    server.use(http.get(`${BASE}/community/overview`, () => HttpResponse.json({ data: "bad" })));

    expect(await fetchCommunityOverview()).toBeNull();
  });
});
