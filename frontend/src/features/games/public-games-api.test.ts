import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getAllPublicGames, getPublicGames } from "./public-games-api";

const BASE = TEST_API_BASE_URL;

const validGame = {
  id: "g1",
  name: "A Link to the Past",
  slug: "alttp",
  description: "Zelda RPG",
  coverImageUrl: null,
  coverImageAlt: "Cover",
  availability: "available",
  supportedEventTypes: ["lan"],
  steamAppId: null,
};

const validResponse = {
  data: [validGame],
  meta: { total: 1, page: 1, perPage: 24, totalPages: 1 },
};

describe("getPublicGames", () => {
  it("returns game page on success", async () => {
    server.use(http.get(`${BASE}/games`, () => HttpResponse.json(validResponse)));
    const result = await getPublicGames();
    expect(result.games).toHaveLength(1);
    expect(result.games[0].slug).toBe("alttp");
    expect(result.total).toBe(1);
  });

  it("returns empty page on network error", async () => {
    server.use(http.get(`${BASE}/games`, () => HttpResponse.error()));
    const result = await getPublicGames();
    expect(result.games).toHaveLength(0);
  });

  it("returns empty page when response fails type guard", async () => {
    server.use(http.get(`${BASE}/games`, () => HttpResponse.json({ wrong: true })));
    const result = await getPublicGames();
    expect(result.games).toHaveLength(0);
  });

  it("returns empty page on non-OK response", async () => {
    server.use(http.get(`${BASE}/games`, () => new HttpResponse(null, { status: 500 })));
    const result = await getPublicGames();
    expect(result.games).toHaveLength(0);
  });
});

describe("getAllPublicGames", () => {
  it("returns the full flat catalog on success", async () => {
    server.use(http.get(`${BASE}/games`, () => HttpResponse.json({ data: [validGame] })));
    const result = await getAllPublicGames();
    expect(result).toHaveLength(1);
    expect(result[0].slug).toBe("alttp");
  });

  it("returns empty array on network error", async () => {
    server.use(http.get(`${BASE}/games`, () => HttpResponse.error()));
    expect(await getAllPublicGames()).toHaveLength(0);
  });

  it("returns empty array when an item fails the type guard", async () => {
    server.use(http.get(`${BASE}/games`, () => HttpResponse.json({ data: [{ wrong: true }] })));
    expect(await getAllPublicGames()).toHaveLength(0);
  });
});
