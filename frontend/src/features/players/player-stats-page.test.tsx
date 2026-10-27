import { renderToStaticMarkup } from "react-dom/server";

import { isPlayerStatsPage, type PlayerStatsPage } from "./player-stats-api";
import { formatFact, PlayerStatsPageView } from "./player-stats-page";

function stats(overrides: Partial<PlayerStatsPage> = {}): PlayerStatsPage {
  return {
    slug: "jean",
    displayName: "Jean",
    computedAt: "2026-10-27T13:00:00+00:00",
    groups: [
      { key: "games", label: "Parties", facts: [{ key: "runs", label: "Parties jouées", value: 1234, unit: null }] },
      {
        key: "playtime",
        label: "Temps de jeu",
        facts: [{ key: "playedSeconds", label: "Temps connecté sur les parties (total)", value: 5400, unit: "seconds" }],
      },
    ],
    superlatives: [
      { key: "most_generous", label: "Superlatif « Le Parrain »", value: 2 },
      { key: "biggest_hub", label: "Superlatif « Le Facteur »", value: 0 },
    ],
    games: [
      { gameId: "g1", name: "Hollow Knight", slug: "hollow-knight", runs: 3, goals: 2, checks: 540, playedSeconds: 7200, deaths: 4 },
      { gameId: "g2", name: "Jeu sans fiche", slug: null, runs: 1, goals: 0, checks: 10, playedSeconds: 0, deaths: 0 },
    ],
    ...overrides,
  };
}

describe("isPlayerStatsPage (story 30.55)", () => {
  it("accepts the API shape", () => {
    expect(isPlayerStatsPage(stats())).toBe(true);
  });

  it("rejects an unknown unit or a malformed game row", () => {
    const badUnit = { ...stats(), groups: [{ key: "g", label: "G", facts: [{ key: "x", label: "X", value: 1, unit: "km" }] }] };
    expect(isPlayerStatsPage(badUnit)).toBe(false);
    const badGame = { ...stats(), games: [{ gameId: "g1", name: "HK" }] };
    expect(isPlayerStatsPage(badGame)).toBe(false);
  });
});

describe("PlayerStatsPageView (story 30.55)", () => {
  it("renders every group, the won superlatives and the table by game", () => {
    const html = renderToStaticMarkup(<PlayerStatsPageView stats={stats()} />);

    expect(html).toContain("Stats de Jean");
    expect(html).toContain("Parties jouées");
    expect(html).toMatch(/1\s234/);
    expect(html).toContain("1 h 30 min");
    expect(html).toContain("AFK compris");
    expect(html).toContain("Le Parrain");
    expect(html).not.toContain("Le Facteur");
    expect(html).toContain('href="/jeux/hollow-knight"');
    expect(html).toContain("2 h 00 min");
    expect(html).toContain("Jeu sans fiche");
    expect(html).toContain('href="/joueurs/jean/succes"');
    expect(html).toContain("Mis à jour le 27 octobre");
  });

  it("says when the stats were never computed, and drops an empty table", () => {
    const html = renderToStaticMarkup(<PlayerStatsPageView stats={stats({ computedAt: null, games: [] })} />);

    expect(html).toContain("Pas encore calculé");
    expect(html).not.toContain("Par jeu");
  });

  it("reads a zero duration as a dash", () => {
    expect(formatFact({ key: "t", label: "T", value: 0, unit: "seconds" })).toBe("-");
    expect(formatFact({ key: "n", label: "N", value: 0, unit: null })).toBe("0");
  });
});
