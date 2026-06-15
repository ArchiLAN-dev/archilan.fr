import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { coupleSteamLibrary } from "./steam-coupling-api";

const BASE = TEST_API_BASE_URL;
const ENDPOINT = `${BASE}/games/steam-coupling`;

const matchedGame = {
  id: "g1",
  name: "Hollow Knight",
  slug: "hollow-knight",
  coverImageUrl: null,
  availability: "available",
  steamAppId: 367520,
};

describe("coupleSteamLibrary", () => {
  it("returns matched games on ok", async () => {
    server.use(
      http.post(ENDPOINT, () =>
        HttpResponse.json({
          data: { matchedGames: [matchedGame], ownedCount: 12, matchedCount: 1 },
          meta: { outcome: "ok" },
        }),
      ),
    );

    const result = await coupleSteamLibrary("76561197960287930");

    expect(result.outcome).toBe("ok");
    expect(result.ownedCount).toBe(12);
    expect(result.matchedCount).toBe(1);
    expect(result.matchedGames).toHaveLength(1);
    expect(result.matchedGames[0].slug).toBe("hollow-knight");
  });

  it("maps a private profile outcome", async () => {
    server.use(
      http.post(ENDPOINT, () =>
        HttpResponse.json({
          data: { matchedGames: [], ownedCount: 0, matchedCount: 0 },
          meta: { outcome: "private_profile" },
        }),
      ),
    );

    const result = await coupleSteamLibrary("76561197960287930");

    expect(result.outcome).toBe("private_profile");
    expect(result.matchedGames).toHaveLength(0);
  });

  it("maps a 422 to invalid_input", async () => {
    server.use(http.post(ENDPOINT, () => new HttpResponse(null, { status: 422 })));

    const result = await coupleSteamLibrary("bad");

    expect(result.outcome).toBe("invalid_input");
  });

  it("maps a 502 to steam_error", async () => {
    server.use(http.post(ENDPOINT, () => new HttpResponse(null, { status: 502 })));

    const result = await coupleSteamLibrary("76561197960287930");

    expect(result.outcome).toBe("steam_error");
  });

  it("returns steam_error on network failure", async () => {
    server.use(http.post(ENDPOINT, () => HttpResponse.error()));

    const result = await coupleSteamLibrary("76561197960287930");

    expect(result.outcome).toBe("steam_error");
  });

  it("returns steam_error when the response shape is invalid", async () => {
    server.use(http.post(ENDPOINT, () => HttpResponse.json({ wrong: true })));

    const result = await coupleSteamLibrary("76561197960287930");

    expect(result.outcome).toBe("steam_error");
  });
});
