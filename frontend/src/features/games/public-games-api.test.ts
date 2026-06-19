import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getAllPublicGames, getPublicGame, getPublicGames } from "./public-games-api";

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
  platforms: ["Super Nintendo"],
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

const validDetail = {
  ...validGame,
  coverImageCredit: "ArchiLAN",
  bundledWithAp: false,
  adultContent: false,
  apworld: {
    deployedVersion: "1.0.0",
    latestVersion: "1.0.0",
    sourceUrl: "https://github.com/owner/repo",
    releaseUrl: null,
    updateStatus: "up_to_date",
  },
  options: [{ key: "goal", min: 0, max: 3, default: 1 }],
  catalog: { notes: "Tested", links: [{ label: "Releases", url: "https://example.org" }] },
};

describe("getPublicGame", () => {
  it("returns the detail on success", async () => {
    server.use(http.get(`${BASE}/games/alttp`, () => HttpResponse.json({ data: validDetail })));
    const result = await getPublicGame("alttp");
    expect(result?.slug).toBe("alttp");
    expect(result?.options).toHaveLength(1);
    expect(result?.catalog.notes).toBe("Tested");
  });

  it("returns null on 404", async () => {
    server.use(http.get(`${BASE}/games/missing`, () => new HttpResponse(null, { status: 404 })));
    expect(await getPublicGame("missing")).toBeNull();
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/games/alttp`, () => HttpResponse.error()));
    expect(await getPublicGame("alttp")).toBeNull();
  });

  it("returns null when payload fails the type guard", async () => {
    server.use(
      http.get(`${BASE}/games/alttp`, () => HttpResponse.json({ data: { ...validDetail, apworld: null } })),
    );
    expect(await getPublicGame("alttp")).toBeNull();
  });

  it("returns null when a catalog link is malformed", async () => {
    server.use(
      http.get(`${BASE}/games/alttp`, () =>
        HttpResponse.json({ data: { ...validDetail, catalog: { notes: null, links: [{ label: 42 }] } } }),
      ),
    );
    expect(await getPublicGame("alttp")).toBeNull();
  });
});
