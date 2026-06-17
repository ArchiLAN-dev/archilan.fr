import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getPlayerProfile, getPlayerHistory } from "./player-profile-api";

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
