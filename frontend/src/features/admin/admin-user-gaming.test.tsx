import { renderToStaticMarkup } from "react-dom/server";

import { GameList, buildGameRows, canStopRun, gameFilterOf, groupHistory, orderRuns, runStatus } from "./admin-user-gaming";
import type { AdminUserHistoryEntry, AdminUserRun } from "./admin-users-api";

const run = (id: string, status: string, sessionId: string | null = null, games: string[] = []): AdminUserRun => ({ id, title: `Run ${id}`, status, sessionId, games });

/** Story 36.9: the game tab of the admin sheet, readable at a glance. */
describe("personal runs", () => {
  test("every status the run exposes reads in French", () => {
    expect(["draft", "starting", "active", "stopping", "idle", "restarting", "completed", "cancelled"].map((s) => runStatus(s).label)).toEqual([
      "Brouillon",
      "Démarrage",
      "En cours",
      "Arrêt en cours",
      "En veille",
      "Redémarrage",
      "Terminée",
      "Annulée",
    ]);
    expect(runStatus("mystery").label).toBe("mystery");
  });

  test("live runs first, then drafts, then the finished; owned and joined in one list", () => {
    const ordered = orderRuns([run("a", "completed"), run("b", "draft"), run("c", "idle")], [run("d", "active"), run("e", "completed")]);

    expect(ordered.map((r) => r.id)).toEqual(["c", "d", "b", "a", "e"]);
    expect(ordered.find((r) => r.id === "d")?.owned).toBe(false);
  });

  test("only a live run the member owns, with a session, can be stopped", () => {
    expect(canStopRun({ ...run("a", "idle", "s1"), owned: true })).toBe(true);
    expect(canStopRun({ ...run("a", "completed", "s1"), owned: true })).toBe(false);
    expect(canStopRun({ ...run("a", "active", null), owned: true })).toBe(false);
    expect(canStopRun({ ...run("a", "active", "s1"), owned: false })).toBe(false);
  });
});

describe("finished parties", () => {
  const entry = (sessionId: string | null, context: string, game: string, finishedAt: string): AdminUserHistoryEntry => ({ sessionId, context, game, finishedAt });

  test("one row per party: the games of a session merge, a row without a session stands alone", () => {
    const groups = groupHistory([
      entry("s1", "test", "Paint", "2026-07-27T20:00:00Z"),
      entry("s1", "test", "Luigi's Mansion", "2026-07-27T20:00:00Z"),
      entry("s1", "test", "Paint", "2026-07-27T20:00:00Z"),
      entry(null, "Weekly", "Minecraft", "2026-07-20T20:00:00Z"),
      entry(null, "Weekly", "Minecraft", "2026-07-13T20:00:00Z"),
    ]);

    expect(groups).toHaveLength(3);
    expect(groups[0]).toMatchObject({ context: "test", games: ["Paint", "Luigi's Mansion"] });
  });
});

describe("runs et parties", () => {
  const entry = (sessionId: string | null, context: string, game: string, finishedAt: string): AdminUserHistoryEntry => ({ sessionId, context, game, finishedAt });

  test("a finished run and its party are one line: the run's link, the party's date and games", () => {
    const rows = buildGameRows(
      [run("a", "completed", "s1"), run("b", "idle", "s2")],
      [],
      [entry("s1", "Run a", "Paint", "2026-07-27T20:00:00Z"), entry("s9", "ArchiLAN #3", "Minecraft", "2026-08-01T20:00:00Z")],
    );

    expect(rows.map((r) => r.key)).toEqual(["b", "s9", "a"]);
    expect(rows[2]).toMatchObject({ href: "/runs/a", finishedAt: "2026-07-27T20:00:00Z", games: ["Paint"] });
    expect(rows[1]).toMatchObject({ title: "ArchiLAN #3", href: null, run: null });
    expect(rows.map(gameFilterOf)).toEqual(["live", "done", "done"]);
  });

  test("a run not finished yet shows the games the member picked; a finished one, the games played", () => {
    const rows = buildGameRows(
      [run("a", "draft", null, ["Paint", "Minecraft"]), run("b", "completed", "s1", ["Paint"])],
      [],
      [entry("s1", "Run b", "Luigi's Mansion", "2026-07-27T20:00:00Z")],
    );

    expect(rows.find((r) => r.key === "a")?.games).toEqual(["Paint", "Minecraft"]);
    expect(rows.find((r) => r.key === "b")?.games).toEqual(["Luigi's Mansion"]);
  });

  test("filters with their counts, five lines per page, the stop action on live runs only", () => {
    const owned = [run("a", "active", "s1"), run("b", "draft"), ...["c", "d", "e", "f"].map((id) => run(id, "completed", `s-${id}`))];
    const html = renderToStaticMarkup(<GameList onStopped={async () => {}} rows={buildGameRows(owned, [run("g", "idle", "s-g")], [])} userId="u1" />);

    expect(html).toMatch(/aria-pressed="true"[^>]*>Tout<span[^>]*>7</);
    expect(html).toMatch(/>En cours<span[^>]*>2</);
    expect(html).toMatch(/>Brouillons<span[^>]*>1</);
    expect(html).toMatch(/>Terminées<span[^>]*>4</);
    expect(html).toContain("Page 1 sur 2");
    expect(html).toContain("Invité");
    expect(html.match(/>Arrêter</g)).toHaveLength(1);
  });

  test("a list that fits on one page has no pager", () => {
    const html = renderToStaticMarkup(<GameList onStopped={async () => {}} rows={buildGameRows([], [], [entry("s1", "Seule", "Paint", "2026-07-27T20:00:00Z")])} userId="u1" />);

    expect(html).toContain("Seule");
    expect(html).not.toContain("Page 1");
  });
});
