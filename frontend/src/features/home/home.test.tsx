import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import type { PublicEvent } from "@/features/events/event-types";
import { getHomeRecaps, getHomeWeeklyRuns, newestFirst } from "./home-api";
import { HomeCommunity, HomeConcept, HomeLan, HomeNews, HomeNow, HomeRecaps } from "./home-sections";

const BASE = TEST_API_BASE_URL;

function event(id: string, dateIso: string, players: number, status: PublicEvent["status"] = "completed"): PublicEvent {
  return { id, title: `ArchiLAN #${id}`, date: dateIso.slice(0, 10), dateIso, location: "Clermont-Ferrand", description: "", status, capacity: { total: players, remaining: 0 } };
}

const lan1 = event("1", "2024-11-09T09:00:00+00:00", 15);
const lan2 = event("2", "2025-02-21T17:00:00+00:00", 29);
const lan3 = event("3", "2025-11-07T17:00:00+00:00", 50);

/** Story 34.9: the home page, newcomer first, folding what has nothing real to show. */
describe("home page data", () => {
  test("past events are put newest first, whatever the API order", () => {
    expect(newestFirst([lan1, lan3, lan2]).map((e) => e.id)).toEqual(["3", "2", "1"]);
  });

  test("only the active weekly runs are shown", async () => {
    const run = (id: string, status: string) => ({
      weeklyRunId: id, isGenerated: true, templateName: id, yamlConfig: null, gameName: "Game", coverImageUrl: null, weekNumber: 41, weekYear: 2026,
      status, startedAt: null, finishedAt: null, leaderboard: { fastest: [], fewestChecks: [], fewestItems: [] }, participants: [], myEntry: null,
    });
    server.use(http.get(`${BASE}/weekly-runs/current`, () => HttpResponse.json({ data: [run("a", "active"), run("b", "finished")] })));
    expect((await getHomeWeeklyRuns()).map((r) => r.weeklyRunId)).toEqual(["a"]);
  });

  test("the latest finished recaps across the recent LANs, with their LAN", async () => {
    const entry = (sessionId: string, finishedAt: string | null) => ({ sessionId, startedAt: null, finishedAt, durationSeconds: 3600, playerCount: 12, winner: null });
    server.use(
      http.get(`${BASE}/events/3/parties`, () => HttpResponse.json({ data: [entry("s3", "2025-11-09T10:00:00+00:00"), entry("open", null)] })),
      http.get(`${BASE}/events/2/parties`, () => HttpResponse.json({ data: [entry("s2", "2025-02-23T10:00:00+00:00")] })),
      http.get(`${BASE}/events/1/parties`, () => HttpResponse.json({ data: [entry("s1", "2024-11-10T10:00:00+00:00")] })),
    );
    const recaps = await getHomeRecaps([lan3, lan2, lan1]);
    expect(recaps.map((r) => [r.sessionId, r.eventTitle])).toEqual([
      ["s3", "ArchiLAN #3"],
      ["s2", "ArchiLAN #2"],
    ]);
  });
});

describe("home page sections", () => {
  test("the « right now » chips say what is open and when the next LAN is", () => {
    const html = renderToStaticMarkup(<HomeNow nextEvent={null} weeklyRuns={3} />);
    expect(html).toContain("3 runs hebdos ouvertes");
    expect(html).not.toContain("en ligne");
    expect(html).toContain("bientôt annoncée");
    expect(renderToStaticMarkup(<HomeNow nextEvent={null} weeklyRuns={0} />)).not.toContain("runs hebdos");
  });

  test("the concept shows three games only when it has three covers", () => {
    const covers = ["A", "B", "C"].map((name) => ({ name, url: `https://img.test/${name}.jpg` }));
    expect(renderToStaticMarkup(<HomeConcept covers={covers} />)).toContain("Jaquette de B");
    expect(renderToStaticMarkup(<HomeConcept covers={covers.slice(0, 2)} />)).not.toContain("Jaquette");
  });

  test("with no LAN announced, the last one is featured and the Discord warns of the next", () => {
    const html = renderToStaticMarkup(<HomeLan past={[lan3, lan2, lan1]} upcoming={[]} />);
    expect(html).toContain("ArchiLAN #3 · 2025-11-07");
    expect(html).toContain("Être prévenu sur Discord");
    expect(html.indexOf("#1 ·")).toBeLessThan(html.lastIndexOf("#3 ·"));
  });

  test("an announced LAN is featured with its places", () => {
    const next = { ...event("4", "2026-11-06T17:00:00+00:00", 60, "open"), capacity: { total: 60, remaining: 12 } };
    const html = renderToStaticMarkup(<HomeLan past={[lan3]} upcoming={[next]} />);
    expect(html).toContain("12 places restantes");
    expect(html).toContain("/evenements/4");
  });

  test("recaps and news fold away when there are none", () => {
    expect(renderToStaticMarkup(<HomeRecaps recaps={[]} />)).toBe("");
    expect(renderToStaticMarkup(<HomeNews posts={[]} />)).toBe("");
    expect(renderToStaticMarkup(<HomeLan past={[]} upcoming={[]} />)).toBe("");
  });

  test("the community figures read real counts, formatted", () => {
    const html = renderToStaticMarkup(<HomeCommunity discord={{ members: 95, online: 45 }} stats={{ totalFinishedSessions: 86, totalChecksDone: 15817, totalGoalsReached: 58 }} />);
    expect(html).toMatch(/15\s817/);
    expect(html).toContain("membres sur le Discord");
  });
});
