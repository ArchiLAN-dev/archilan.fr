import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { CommunityView, EventsView, PellesView, PeriodPicker, SessionsView } from "./admin-stats-page";
import { deltaText } from "./key-figure";
import {
  fetchCommunityStats,
  fetchEventStats,
  fetchPelleStats,
  fetchSessionStats,
  parseStatsPeriod,
  type CommunityStats,
  type EventStats,
  type PelleStats,
  type SessionStats,
  type Trend,
} from "./stats-api";
import { TrendChart, bucketLabel } from "./trend-chart";

const BASE = TEST_API_BASE_URL;

function trend(values: number[], previous: number): Trend {
  const starts = ["2026-09-07", "2026-09-14", "2026-09-21", "2026-09-28"];
  return {
    series: values.map((value, index) => ({ start: starts[index] ?? "", value, current: index === values.length - 1 })),
    total: values.reduce((sum, v) => sum + v, 0),
    previous,
  };
}

const period = { code: "4s", granularity: "week" as const, start: "2026-09-07T00:00:00+00:00", end: "2026-10-05T00:00:00+00:00", previousStart: "2026-08-10T00:00:00+00:00" };

const community: CommunityStats = {
  period,
  accounts: 42,
  members: 12,
  accountsCreated: trend([1, 0, 0, 2], 1),
  membershipsStarted: trend([0, 1, 0, 0], 0),
  activePlayers: { ...trend([2, 0, 0, 2], 2), total: 2 },
  friendshipsAccepted: trend([0, 0, 1, 0], 2),
  achievementsUnlocked: trend([0, 0, 0, 1], 1),
};

const sessions: SessionStats = {
  period,
  runningSessions: 1,
  activeRuns: 1,
  runsCreated: trend([1, 0, 0, 0], 1),
  runsLaunched: { ...trend([1, 0, 0, 1], 1), total: 1 },
  eventSessionsLaunched: trend([0, 1, 0, 0], 0),
  weeklyLaunched: trend([0, 0, 1, 0], 0),
  weeklyCompleted: trend([0, 0, 1, 0], 0),
  goalsReached: trend([0, 0, 0, 1], 0),
  topGames: [{ gameId: "g1", name: "Celeste", players: 2, checks: 1 }],
};

const events: EventStats = {
  period,
  upcomingEvents: 1,
  registrations: trend([1, 1, 0, 1], 1),
  cancellations: trend([0, 1, 0, 0], 0),
  revenue: trend([1500, 0, 0, 1500], 700),
  revenueByType: { events: trend([1500, 0, 0, 0], 700), memberships: trend([0, 0, 0, 1000], 0), shop: trend([0, 0, 0, 500], 0) },
  events: [{ eventId: "e1", title: "ArchiLAN #3", startsAt: "2026-09-20T10:00:00+00:00", status: "published", capacity: 10, registrations: 2, fillRate: 20 }],
};

const pelles: PelleStats = {
  period,
  goldInCirculation: 60,
  created: trend([0, 0, 0, 100], 7),
  destroyed: trend([0, 0, 0, 40], 0),
  byReason: [{ reason: "admin_credit", created: 100, destroyed: 0 }],
};

/** Story 42.1: the admin statistics page. */
describe("period", () => {
  test("an unknown value falls back to 12 weeks", () => {
    expect(parseStatsPeriod("12m")).toBe("12m");
    expect(parseStatsPeriod("2ans")).toBe("12s");
    expect(parseStatsPeriod(null)).toBe("12s");
  });

  test("the picker marks the chosen period", () => {
    const html = renderToStaticMarkup(<PeriodPicker onChoose={() => {}} value="4s" />);

    expect(html).toContain('aria-pressed="true"');
    expect(html.match(/aria-pressed="true"[^>]*>4 semaines/)).not.toBeNull();
  });

  test("buckets are named by week or by month", () => {
    expect(bucketLabel("2026-09-28", "week")).toBe("28 sept.");
    expect(bucketLabel("2026-10-01", "month")).toBe("oct. 2026");
  });
});

describe("deltaText", () => {
  test("compares with the period before", () => {
    expect(deltaText(5, 4)).toBe("+1 (+25 %)");
    expect(deltaText(3, 5)).toBe("-2 (-40 %)");
    expect(deltaText(4, 4)).toBe("=");
    expect(deltaText(3, 0)).toBe("nouveau");
    expect(deltaText(0, 0)).toBe("=");
  });
});

describe("sections", () => {
  test("Community shows its key figures and the definitions of its charts", () => {
    const html = renderToStaticMarkup(<CommunityView stats={community} />);

    expect(html).toContain("Comptes créés");
    expect(html).toContain("+2 (+200 %)");
    expect(html).toContain("Adhérents à jour");
    expect(html).toContain("Mesuré depuis juillet 2026");
  });

  test("Parties shows the launches, the goals and the most played games", () => {
    const html = renderToStaticMarkup(<SessionsView stats={sessions} />);

    expect(html).toContain("Runs lancées");
    expect(html).toContain("Sessions en cours");
    expect(html).toContain("une relance compte pour la même run");
    expect(html).toContain('<td class="py-2">Celeste</td>');
  });

  test("Parties says when nothing was played", () => {
    expect(renderToStaticMarkup(<SessionsView stats={{ ...sessions, topGames: [] }} />)).toContain("Aucun check sur la période.");
  });

  test("Events shows registrations, revenue in euros and the filling of each event", () => {
    const html = renderToStaticMarkup(<EventsView stats={events} />);

    expect(html).toContain("Recettes HelloAsso");
    expect(html).toContain("30\u00a0€");
    expect(html).toContain("ArchiLAN #3");
    expect(html).toContain("2 / 10");
    expect(html).toContain("20 %");
    expect(html).toContain("Publié");
  });

  test("Events says when no event falls in the period", () => {
    expect(renderToStaticMarkup(<EventsView stats={{ ...events, events: [] }} />)).toContain("Aucun événement sur la période.");
  });

  test("Pelles shows the circulation, the flows and the reasons", () => {
    const html = renderToStaticMarkup(<PellesView stats={pelles} />);

    expect(html).toContain("En circulation");
    expect(html).toContain("nouveau");
    expect(html).toContain("Crédit de l&#x27;équipe");
  });

  test("a chart carries its numbers in a table for screen readers, the bucket in progress named", () => {
    const html = renderToStaticMarkup(
      <TrendChart
        caption="Comptes créés"
        granularity="week"
        kind="bar"
        series={[{ key: "a", label: "Comptes créés", color: "red", buckets: community.accountsCreated.series }]}
      />,
    );

    expect(html).toContain("<caption>Comptes créés</caption>");
    expect(html).toContain("<td>28 sept. (en cours)</td><td>2</td>");
  });
});

describe("stats API", () => {
  test("fetches a section for the period", async () => {
    let asked: string | null = null;
    server.use(
      http.get(`${BASE}/admin/statistiques/communaute`, ({ request }) => {
        asked = new URL(request.url).searchParams.get("period");
        return HttpResponse.json(community);
      }),
    );

    expect(await fetchCommunityStats("4s")).toEqual(community);
    expect(asked).toBe("4s");
  });

  test("fetches the Parties section", async () => {
    server.use(http.get(`${BASE}/admin/statistiques/parties`, () => HttpResponse.json(sessions)));
    expect(await fetchSessionStats("4s")).toEqual(sessions);

    server.use(http.get(`${BASE}/admin/statistiques/parties`, () => HttpResponse.json({ ...sessions, topGames: [{ name: "x" }] })));
    expect(await fetchSessionStats("4s")).toBeNull();
  });

  test("fetches the Events section", async () => {
    server.use(http.get(`${BASE}/admin/statistiques/evenements`, () => HttpResponse.json(events)));
    expect(await fetchEventStats("4s")).toEqual(events);

    server.use(http.get(`${BASE}/admin/statistiques/evenements`, () => HttpResponse.json({ ...events, revenueByType: {} })));
    expect(await fetchEventStats("4s")).toBeNull();
  });

  test("a section in error or with an unexpected body is null", async () => {
    server.use(http.get(`${BASE}/admin/statistiques/pelles`, () => new HttpResponse(null, { status: 500 })));
    expect(await fetchPelleStats("12s")).toBeNull();

    server.use(http.get(`${BASE}/admin/statistiques/pelles`, () => HttpResponse.json({ goldInCirculation: 1 })));
    expect(await fetchPelleStats("12s")).toBeNull();

    server.use(http.get(`${BASE}/admin/statistiques/pelles`, () => HttpResponse.json(pelles)));
    expect(await fetchPelleStats("12s")).toEqual(pelles);
  });
});
