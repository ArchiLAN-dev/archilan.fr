import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { renderToStaticMarkup } from "react-dom/server";

import { apiFetch } from "../../lib/apiFetch";
import { CatalogTrackerPackSuggestions, GamesToEquipPanel } from "./admin-catalog-tracker-packs";
import {
  catalogTrackerPacksQueryKey,
  fetchCatalogTrackerPacks,
  fetchGamesToEquip,
  gamesToEquipQueryKey,
  type CatalogTrackerPack,
  type GameToEquip,
} from "./admin-catalog-tracker-packs-api";

jest.mock("../../lib/apiFetch");

const mockApiFetch = jest.mocked(apiFetch);

const githubPack: CatalogTrackerPack = {
  label: "Poptracker",
  url: "https://github.com/seto10987/Majoras-Mask-AP-PopTracker-Pack/releases/latest",
  importUrl: "https://github.com/seto10987/Majoras-Mask-AP-PopTracker-Pack",
  repository: "seto10987/Majoras-Mask-AP-PopTracker-Pack",
};

const discordPack: CatalogTrackerPack = {
  label: "Poptracker (Discord)",
  url: "https://discord.com/channels/1/2/3",
  importUrl: null,
  repository: null,
};

function render(node: ReactNode, seed: (client: QueryClient) => void): string {
  const client = new QueryClient();
  seed(client);
  return renderToStaticMarkup(<QueryClientProvider client={client}>{node}</QueryClientProvider>);
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

describe("CatalogTrackerPackSuggestions (story 54.12)", () => {
  it("proposes the catalog's GitHub pack with an import button, and shows another link without one", () => {
    const html = render(<CatalogTrackerPackSuggestions busy={false} gameId="g1" onImport={() => undefined} />, (client) =>
      client.setQueryData(catalogTrackerPacksQueryKey("g1"), [githubPack, discordPack]),
    );

    expect(html).toContain("Pack recommandé par le catalogue");
    expect(html).toContain("seto10987/Majoras-Mask-AP-PopTracker-Pack");
    expect(html).toContain("Importer");
    expect(html).toContain("Hors GitHub : à récupérer à la main");
    expect(html).toContain('href="https://discord.com/channels/1/2/3"');
  });

  it("shows nothing when the catalog has no pack or cannot be read", () => {
    expect(render(<CatalogTrackerPackSuggestions busy={false} gameId="g1" onImport={() => undefined} />, (client) =>
      client.setQueryData(catalogTrackerPacksQueryKey("g1"), []),
    )).toBe("");
    expect(render(<CatalogTrackerPackSuggestions busy={false} gameId="g1" onImport={() => undefined} />, (client) =>
      client.setQueryData(catalogTrackerPacksQueryKey("g1"), null),
    )).toBe("");
  });

  it("waits while an import of the game runs", () => {
    const html = render(<CatalogTrackerPackSuggestions busy gameId="g1" onImport={() => undefined} />, (client) =>
      client.setQueryData(catalogTrackerPacksQueryKey("g1"), [githubPack]),
    );
    expect(html).toMatch(/<button[^>]*disabled=""/);
  });
});

describe("GamesToEquipPanel (story 54.12)", () => {
  const game: GameToEquip = { gameId: "g1", gameName: "Majora's Mask", gameSlug: "mm", suggestions: [githubPack] };

  it("counts the games to equip, folded at first", () => {
    const html = render(<GamesToEquipPanel />, (client) => client.setQueryData(gamesToEquipQueryKey, [game]));
    expect(html).toContain("Trackers proposés par le catalogue (1)");
    expect(html).toContain('aria-expanded="false"');
  });

  it("shows nothing when no game is waiting", () => {
    expect(render(<GamesToEquipPanel />, (client) => client.setQueryData(gamesToEquipQueryKey, []))).toBe("");
  });
});

describe("catalog tracker packs API", () => {
  afterEach(() => mockApiFetch.mockReset());

  it("reads the suggestions of a game", async () => {
    mockApiFetch.mockResolvedValueOnce(json({ data: [githubPack, discordPack] }));
    await expect(fetchCatalogTrackerPacks("g 1")).resolves.toEqual([githubPack, discordPack]);
    expect(mockApiFetch.mock.calls[0]?.[0]).toContain("/admin/games/g%201/tracker-pack-suggestions");
  });

  it("gives null on a refusal or an unexpected shape", async () => {
    mockApiFetch.mockResolvedValueOnce(json({ error: { code: "forbidden" } }, 403));
    await expect(fetchGamesToEquip()).resolves.toBeNull();
    mockApiFetch.mockResolvedValueOnce(json({ data: [{ gameId: "g1" }] }));
    await expect(fetchGamesToEquip()).resolves.toBeNull();
  });
});
