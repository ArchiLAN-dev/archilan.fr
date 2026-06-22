import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getPlayerProfile, getPlayerHistory, getPlayerAchievements } from "./player-profile-api";

const BASE = TEST_API_BASE_URL;

const validStats = {
  runsParticipated: 3,
  goalCompletions: 2,
  goalCompletionRate: 0.67,
  totalChecksDone: 150,
  totalItemsReceived: 80,
};

describe("getPlayerProfile", () => {
  it("returns profile on success", async () => {
    server.use(
      http.get(`${BASE}/community/profiles/jean`, () =>
        HttpResponse.json({
          data: { slug: "jean", displayName: "Jean", joinedAt: "2024-01-01", avatarUrl: null, audience: "public", customization: null, stats: validStats },
        }),
      ),
    );
    const result = await getPlayerProfile("jean");
    expect(result).not.toBeNull();
    expect(result?.slug).toBe("jean");
    expect(result?.displayName).toBe("Jean");
    expect(result?.avatarUrl).toBeNull();
  });

  it("returns profile when displayName is null", async () => {
    server.use(
      http.get(`${BASE}/community/profiles/anon`, () =>
        HttpResponse.json({
          data: { slug: "anon", displayName: null, joinedAt: "2024-01-01", avatarUrl: null, audience: "public", customization: null, stats: validStats },
        }),
      ),
    );
    const result = await getPlayerProfile("anon");
    expect(result).not.toBeNull();
    expect(result?.displayName).toBeNull();
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/community/profiles/errslug`, () => HttpResponse.error()));
    expect(await getPlayerProfile("errslug")).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(
      http.get(`${BASE}/community/profiles/badslug`, () => HttpResponse.json({ data: { wrong: true } })),
    );
    expect(await getPlayerProfile("badslug")).toBeNull();
  });

  it("parses achievementStats and the recent slice", async () => {
    server.use(
      http.get(`${BASE}/community/profiles/withstats`, () =>
        HttpResponse.json({
          data: {
            slug: "withstats", displayName: "S", joinedAt: "2024-01-01", avatarUrl: null, audience: "public", customization: null, stats: validStats,
            achievementStats: { unlocked: 3, total: 9 },
            achievements: [{ key: "first_run", name: "First", description: "d", unlocked: true, unlockedAt: "2024-01-02", grantId: null, kudosCount: 0 }],
          },
        }),
      ),
    );
    const result = await getPlayerProfile("withstats");
    expect(result?.achievementStats).toEqual({ unlocked: 3, total: 9 });
    expect(result?.achievements).toHaveLength(1);
  });

  it("defaults achievementStats when missing", async () => {
    server.use(
      http.get(`${BASE}/community/profiles/nostats`, () =>
        HttpResponse.json({
          data: { slug: "nostats", displayName: "N", joinedAt: "2024-01-01", avatarUrl: null, audience: "public", customization: null, stats: validStats },
        }),
      ),
    );
    const result = await getPlayerProfile("nostats");
    expect(result?.achievementStats).toEqual({ unlocked: 0, total: 0 });
  });
});

describe("getPlayerAchievements", () => {
  const ach = (over: Record<string, unknown> = {}) => ({
    key: "first_run", name: "First Run", description: "d", unlocked: true, unlockedAt: "2024-01-02", grantId: null, kudosCount: 0,
    customImageUrl: "http://minio.test/badge.png", rarity: { count: 2, percent: 50 }, ...over,
  });

  it("returns the catalogue with rarity and custom image on success", async () => {
    server.use(
      http.get(`${BASE}/community/profiles/cat/achievements`, () =>
        HttpResponse.json({
          data: {
            slug: "cat", displayName: "Cat", avatarUrl: null,
            achievements: [ach(), ach({ key: "omnivore", unlocked: false, unlockedAt: null, customImageUrl: null, rarity: { count: 0, percent: 0 } })],
          },
        }),
      ),
    );
    const result = await getPlayerAchievements("cat");
    expect(result).not.toBeNull();
    expect(result?.achievements).toHaveLength(2);
    expect(result?.achievements[0].rarity.percent).toBe(50);
    expect(result?.achievements[0].customImageUrl).toBe("http://minio.test/badge.png");
    expect(result?.achievements[1].customImageUrl).toBeNull();
  });

  it("accepts a null rarity percent", async () => {
    server.use(
      http.get(`${BASE}/community/profiles/catnull/achievements`, () =>
        HttpResponse.json({
          data: { slug: "catnull", displayName: null, avatarUrl: null, achievements: [ach({ rarity: { count: 1, percent: null } })] },
        }),
      ),
    );
    const result = await getPlayerAchievements("catnull");
    expect(result?.achievements[0].rarity.percent).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(
      http.get(`${BASE}/community/profiles/badcat/achievements`, () => HttpResponse.json({ data: { slug: "badcat" } })),
    );
    expect(await getPlayerAchievements("badcat")).toBeNull();
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/community/profiles/errcat/achievements`, () => HttpResponse.error()));
    expect(await getPlayerAchievements("errcat")).toBeNull();
  });
});

describe("getPlayerHistory", () => {
  const validEntry = {
    sessionId: "s1",
    eventName: "LAN 2024",
    finishedAt: "2024-06-01",
    game: "alttp",
    checksDone: 50,
    itemsReceived: 30,
    goalReachedAt: null,
    wasReleased: false,
    isInvalidated: false,
    isWeekly: false,
  };

  it("returns history on success", async () => {
    server.use(
      http.get(`${BASE}/players/jean/history`, () =>
        HttpResponse.json({ data: [validEntry], meta: { page: 1, limit: 100, total: 1 } }),
      ),
    );
    const result = await getPlayerHistory("jean");
    expect(result).not.toBeNull();
    expect(result?.data).toHaveLength(1);
    expect(result?.data[0].sessionId).toBe("s1");
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/players/errslug2/history`, () => HttpResponse.error()));
    expect(await getPlayerHistory("errslug2")).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(
      http.get(`${BASE}/players/badslug2/history`, () => HttpResponse.json({ wrong: true })),
    );
    expect(await getPlayerHistory("badslug2")).toBeNull();
  });
});
