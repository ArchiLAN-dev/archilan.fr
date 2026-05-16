import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import {
  getCatalogGames,
  getGameRequests,
  submitGameRequest,
  cancelGameRequest,
} from "./game-request-api";

const BASE = TEST_API_BASE_URL;

describe("getCatalogGames", () => {
  it("returns game names on success", async () => {
    server.use(
      http.get(`${BASE}/catalog-games`, () =>
        HttpResponse.json({ data: ["alttp", "sm"] }),
      ),
    );
    const result = await getCatalogGames();
    expect(result).toEqual(["alttp", "sm"]);
  });

  it("returns empty array on network error", async () => {
    server.use(http.get(`${BASE}/catalog-games`, () => HttpResponse.error()));
    expect(await getCatalogGames()).toEqual([]);
  });

  it("returns empty array when response fails type guard", async () => {
    server.use(http.get(`${BASE}/catalog-games`, () => HttpResponse.json({ data: [1, 2] })));
    expect(await getCatalogGames()).toEqual([]);
  });
});

describe("getGameRequests", () => {
  const validItem = {
    normalizedName: "alttp",
    displayName: "A Link to the Past",
    voteCount: 3,
    hasVoted: false,
  };

  it("returns game request items on success", async () => {
    server.use(
      http.get(`${BASE}/game-requests`, () => HttpResponse.json({ data: [validItem] })),
    );
    const result = await getGameRequests();
    expect(result).toHaveLength(1);
    expect(result[0].normalizedName).toBe("alttp");
  });

  it("returns empty array on network error", async () => {
    server.use(http.get(`${BASE}/game-requests`, () => HttpResponse.error()));
    expect(await getGameRequests()).toEqual([]);
  });

  it("returns empty array when response fails type guard", async () => {
    server.use(http.get(`${BASE}/game-requests`, () => HttpResponse.json({ data: [{ x: 1 }] })));
    expect(await getGameRequests()).toEqual([]);
  });
});

describe("submitGameRequest", () => {
  it("returns ok on 201", async () => {
    server.use(
      http.post(`${BASE}/game-requests`, () => new HttpResponse(null, { status: 201 })),
    );
    const result = await submitGameRequest("alttp");
    expect(result).toEqual({ ok: true, alreadyVoted: false });
  });

  it("returns alreadyVoted on 409", async () => {
    server.use(
      http.post(`${BASE}/game-requests`, () => new HttpResponse(null, { status: 409 })),
    );
    const result = await submitGameRequest("alttp");
    expect(result).toEqual({ ok: false, alreadyVoted: true });
  });

  it("returns network error response on fetch failure", async () => {
    server.use(http.post(`${BASE}/game-requests`, () => HttpResponse.error()));
    const result = await submitGameRequest("alttp");
    expect(result.ok).toBe(false);
    expect(result.alreadyVoted).toBe(false);
  });
});

describe("cancelGameRequest", () => {
  it("returns true on success", async () => {
    server.use(
      http.delete(`${BASE}/game-requests/alttp`, () => new HttpResponse(null, { status: 204 })),
    );
    expect(await cancelGameRequest("alttp")).toBe(true);
  });

  it("returns false on network error", async () => {
    server.use(http.delete(`${BASE}/game-requests/alttp`, () => HttpResponse.error()));
    expect(await cancelGameRequest("alttp")).toBe(false);
  });

  it("returns false on non-OK response", async () => {
    server.use(
      http.delete(`${BASE}/game-requests/alttp`, () => new HttpResponse(null, { status: 404 })),
    );
    expect(await cancelGameRequest("alttp")).toBe(false);
  });
});
