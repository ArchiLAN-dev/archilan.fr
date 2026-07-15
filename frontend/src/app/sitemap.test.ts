import { http, HttpResponse } from "msw";

import { server } from "../tests/setup";
import { TEST_API_BASE_URL } from "../tests/constants";
import sitemap from "./sitemap";

const BASE = TEST_API_BASE_URL;

const validEvent = {
  id: "evt-1",
  title: "LAN 2024",
  description: "Grande LAN",
  coverImageUrl: null,
  photoGallery: [],
  type: "lan",
  status: "published",
  startsAt: "2024-06-01T10:00:00Z",
  endsAt: "2024-06-02T18:00:00Z",
  venue: "Salle des fêtes",
  capacity: 50,
  confirmedRegistrations: 10,
  registrationOpensAt: "2024-01-01T00:00:00Z",
  registrationClosesAt: "2024-05-31T23:59:59Z",
  isPublic: true,
  hasPrivateAccessPassword: false,
  vodUrl: null,
  recapPostSlug: null,
  hasRecap: false,
  checkoutEmbedUrl: null,
  checkoutUnavailable: false,
};

const validPost = {
  slug: "news-test",
  title: "Test news",
  type: "news",
  status: "published",
  excerpt: "A short excerpt",
  coverImageUrl: null,
  body: ["Paragraph one"],
  readingTime: "2 min",
  publishedAt: "2024-06-01T10:00:00Z",
};

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

const validWeeklyRun = { gameName: "Super Metroid" };

function allSourcesReturn(): void {
  server.use(
    http.get(`${BASE}/events`, () => HttpResponse.json({ data: [validEvent] })),
    http.get(`${BASE}/posts`, () => HttpResponse.json({ data: [validPost] })),
    http.get(`${BASE}/games`, () => HttpResponse.json({ data: [validGame] })),
    http.get(`${BASE}/weekly-runs/current`, () => HttpResponse.json({ data: [validWeeklyRun] })),
  );
}

async function urls(): Promise<string[]> {
  return (await sitemap()).map((entry) => entry.url);
}

describe("sitemap", () => {
  it("includes the static public routes as absolute URLs", async () => {
    allSourcesReturn();
    const result = await urls();

    for (const path of ["/", "/evenements", "/actualites", "/jeux", "/runs-hebdo", "/aide/archipelago", "/mentions-legales"]) {
      expect(result).toContain(`http://localhost:3000${path === "/" ? "/" : path}`);
    }
  });

  it("includes one entry per dynamic source at the crawler-visited route", async () => {
    allSourcesReturn();
    const result = await urls();

    expect(result).toContain("http://localhost:3000/evenements/evt-1");
    expect(result).toContain("http://localhost:3000/actualites/news-test");
    expect(result).toContain("http://localhost:3000/jeux/alttp");
    expect(result).toContain("http://localhost:3000/runs-hebdo/jeu/super-metroid");
  });

  it("sets lastModified on posts from the real published date, and nowhere else", async () => {
    allSourcesReturn();
    const entries = await sitemap();

    const post = entries.find((e) => e.url.endsWith("/actualites/news-test"));
    expect(post?.lastModified).toBe("2024-06-01");

    const event = entries.find((e) => e.url.endsWith("/evenements/evt-1"));
    expect(event?.lastModified).toBeUndefined();
    const game = entries.find((e) => e.url.endsWith("/jeux/alttp"));
    expect(game?.lastModified).toBeUndefined();
  });

  it("never emits a noindex or private route", async () => {
    allSourcesReturn();
    const result = await urls();

    const forbidden = ["/admin", "/o/", "/compte", "/connexion", "/inscription", "/runs/", "/joueurs", "/streams", "/resultats"];
    for (const url of result) {
      for (const bad of forbidden) {
        expect(url).not.toContain(bad);
      }
    }
  });

  it("dedupes weekly-run slugs", async () => {
    server.use(
      http.get(`${BASE}/events`, () => HttpResponse.json({ data: [] })),
      http.get(`${BASE}/posts`, () => HttpResponse.json({ data: [] })),
      http.get(`${BASE}/games`, () => HttpResponse.json({ data: [] })),
      http.get(`${BASE}/weekly-runs/current`, () =>
        HttpResponse.json({ data: [{ gameName: "Super Metroid" }, { gameName: "Super  Metroid" }] }),
      ),
    );

    const weekly = (await urls()).filter((u) => u.includes("/runs-hebdo/jeu/"));
    expect(weekly).toEqual(["http://localhost:3000/runs-hebdo/jeu/super-metroid"]);
  });

  it("still renders the static routes when every API call fails", async () => {
    server.use(
      http.get(`${BASE}/events`, () => HttpResponse.error()),
      http.get(`${BASE}/posts`, () => HttpResponse.error()),
      http.get(`${BASE}/games`, () => HttpResponse.error()),
      http.get(`${BASE}/weekly-runs/current`, () => HttpResponse.error()),
    );

    const result = await urls();

    expect(result).toContain("http://localhost:3000/");
    expect(result).toContain("http://localhost:3000/aide/archipelago");
    // No dynamic entries survived, but the route did not throw.
    expect(result).toHaveLength(14);
  });
});
