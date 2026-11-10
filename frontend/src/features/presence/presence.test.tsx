import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import {
  halfDaySegments,
  isPresentDuring,
  orderedRegistrants,
  parisDays,
  planningCell,
  planningColumns,
  presenceSummary,
  presentCount,
  PresenceTimelineView,
} from "./admin-presence-timeline";
import { describeWindow, isPresence, isPresenceTimeline, type PresenceTimeline } from "./presence-api";
import { PresenceEditor, PresenceSummary, windowsOfRows } from "./presence-editor";

jest.mock("next/navigation", () => ({ useRouter: () => ({ push: jest.fn(), replace: jest.fn() }) }));

// Friday 4 June 2027 18:00 to Sunday 6 June 20:00, Paris time (UTC+2).
const EVENT = { eventStartsAt: "2027-06-04T16:00:00+00:00", eventEndsAt: "2027-06-06T18:00:00+00:00" };

const timeline: PresenceTimeline = {
  ...EVENT,
  registrants: [
    { registrationId: "r1", userId: "u1", displayName: "Alice", windows: [] },
    { registrationId: "r2", userId: "u2", displayName: "Zoé", windows: [{ startsAt: "2027-06-05T08:00:00+00:00", endsAt: "2027-06-06T10:00:00+00:00" }] },
  ],
};

describe("story 52.2 - presence windows", () => {
  it("cuts the event into Paris half-days", () => {
    const segments = halfDaySegments(EVENT.eventStartsAt, EVENT.eventEndsAt);
    expect(segments.map((s) => s.label)).toEqual([
      "ven. 4 après-midi",
      "sam. 5 matin",
      "sam. 5 après-midi",
      "dim. 6 matin",
      "dim. 6 après-midi",
    ]);
    expect(segments[0].startsAt).toBe(new Date(EVENT.eventStartsAt).getTime());
    expect(segments[4].endsAt).toBe(new Date(EVENT.eventEndsAt).getTime());
  });

  it("counts who is there in each half-day", () => {
    const segments = halfDaySegments(EVENT.eventStartsAt, EVENT.eventEndsAt);
    expect(segments.map((s) => presentCount(timeline.registrants, s))).toEqual([1, 2, 2, 2, 1]);
    expect(isPresentDuring([], segments[0])).toBe(true);
  });

  it("renders the timeline with a link to each registration", () => {
    const html = renderToStaticMarkup(<PresenceTimelineView eventId="e1" timeline={timeline} />);
    expect(html).toContain("Alice");
    expect(html).toContain("/admin/evenements/e1/inscriptions/r2");
    // Story 52.8: someone who declared nothing is not shown as a declared presence.
    expect(html).toContain("rien déclaré");
  });

  it("reads the API shapes with guards", () => {
    expect(isPresenceTimeline(timeline)).toBe(true);
    expect(isPresenceTimeline({ ...timeline, registrants: [{ registrationId: "r" }] })).toBe(false);
    expect(isPresence({ ...EVENT, windows: [], editable: true })).toBe(true);
    expect(isPresence({ ...EVENT, windows: [{ startsAt: 1 }], editable: true })).toBe(false);
  });

  it("turns the rows into windows, refusing an incomplete one", () => {
    expect(windowsOfRows([{ start: "2027-06-05T10:00", end: "" }])).toEqual({ error: "Indique une arrivée et un départ pour chaque plage." });
    const ok = windowsOfRows([{ start: "2027-06-05T10:00", end: "2027-06-05T20:00" }]);
    expect("windows" in ok && ok.windows).toHaveLength(1);
  });

  it("shows the windows in words, and the whole event without any", () => {
    expect(describeWindow({ startsAt: "2027-06-04T16:30:00+00:00", endsAt: "2027-06-04T21:00:00+00:00" })).toBe("vendredi 4 juin à 18:30 → 23:00");
    expect(renderToStaticMarkup(<PresenceSummary windows={[]} />)).toContain("Présent tout l&#x27;événement");
  });

  it("offers to add a first window when there is none", () => {
    const client = new QueryClient();
    const html = renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <PresenceEditor intro="intro" onSave={async () => null} presence={{ ...EVENT, windows: [], editable: true }} title="Quand es-tu là ?" />
      </QueryClientProvider>,
    );
    expect(html).toContain("Quand es-tu là ?");
    expect(html).toContain("Je ne suis pas là tout le temps");
  });
});

describe("story 52.8 - a readable timeline", () => {
  const segments = halfDaySegments(EVENT.eventStartsAt, EVENT.eventEndsAt);
  const people: PresenceTimeline["registrants"] = [
    { registrationId: "r1", userId: "u1", displayName: "Zoé", windows: [] },
    { registrationId: "r2", userId: "u2", displayName: "Bob", windows: [{ startsAt: "2027-06-05T12:00:00+00:00", endsAt: "2027-06-06T10:00:00+00:00" }] },
    { registrationId: "r3", userId: "u3", displayName: "Alice", windows: [{ startsAt: "2027-06-04T16:00:00+00:00", endsAt: "2027-06-05T07:30:00+00:00" }] },
    { registrationId: "r4", userId: "u4", displayName: "Aaron", windows: [] },
  ];

  it("sums up who declared and finds the lowest half-day", () => {
    expect(presenceSummary(people, segments)).toEqual({ total: 4, declared: 2, undeclared: 2, low: { label: "dim. 6 après-midi", count: 2 } });
  });

  it("orders the declared by arrival, then those who said nothing by name, and filters them", () => {
    expect(orderedRegistrants(people, "all").map((r) => r.displayName)).toEqual(["Alice", "Bob", "Aaron", "Zoé"]);
    expect(orderedRegistrants(people, "declared").map((r) => r.displayName)).toEqual(["Alice", "Bob"]);
    expect(orderedRegistrants(people, "undeclared").map((r) => r.displayName)).toEqual(["Aaron", "Zoé"]);
  });

  it("shows a planning grid: a column per half-day, full or partial cells, hatched for who said nothing", () => {
    const html = renderToStaticMarkup(<PresenceTimelineView eventId="e1" timeline={{ ...EVENT, registrants: people }} />);

    // Friday evening to Sunday evening: five half-days, the day named on its first one.
    expect(planningColumns(new Date(EVENT.eventStartsAt).getTime(), new Date(EVENT.eventEndsAt).getTime()).map((c) => `${c.top}|${c.bottom}`)).toEqual([
      "ven. 4|aprèm",
      "sam. 5|matin",
      "|aprèm",
      "dim. 6|matin",
      "|aprèm",
    ]);
    // Alice: all Friday evening, Saturday until 9:30. Bob: Saturday from 14 h, all Sunday morning.
    expect(html.match(/data-cell="full"/g)).toHaveLength(2);
    expect(html.match(/data-cell="part"/g)).toHaveLength(2);
    expect(html.match(/: rien déclaré"/g)).toHaveLength(10);
    expect(html).toContain("Non déclarés (2)");
    expect(html).not.toMatch(/\d{4}-\d{2}-\d{2}T/);
  });

  it("filters the rows", () => {
    const html = renderToStaticMarkup(<PresenceTimelineView eventId="e1" initialFilter="undeclared" timeline={{ ...EVENT, registrants: people }} />);
    expect(html).not.toContain("/inscriptions/r2");
    expect(html).toContain("/inscriptions/r4");
    expect(html).not.toContain("Aucun inscrit dans ce filtre");
  });

  it("says so when a filter keeps no one", () => {
    const html = renderToStaticMarkup(
      <PresenceTimelineView eventId="e1" initialFilter="undeclared" timeline={{ ...EVENT, registrants: people.filter((r) => r.windows.length > 0) }} />,
    );
    expect(html).toContain("Aucun inscrit dans ce filtre.");
  });
});

describe("story 52.10 - a long event as a planning grid, a column per day", () => {
  // Tuesday 20 October 2026 to Thursday 5 November, across the change to winter time (25 October).
  const LONG = { eventStartsAt: "2026-10-20T10:00:00+00:00", eventEndsAt: "2026-11-05T18:00:00+00:00" };
  const start = new Date(LONG.eventStartsAt).getTime();
  const end = new Date(LONG.eventEndsAt).getTime();
  const people: PresenceTimeline["registrants"] = [
    { registrationId: "r1", userId: "u1", displayName: "Alice", windows: [{ startsAt: "2026-10-21T08:00:00+00:00", endsAt: "2026-10-22T18:00:00+00:00" }] },
    { registrationId: "r2", userId: "u2", displayName: "Bob", windows: [] },
    { registrationId: "r3", userId: "u3", displayName: "Carol", windows: [{ startsAt: "2026-10-27T08:00:00+00:00", endsAt: "2026-11-03T18:00:00+00:00" }] },
  ];

  it("cuts the civil days of Paris, one per date across the time change", () => {
    const days = parisDays(start, end);

    expect(days).toHaveLength(17);
    expect(days.map((day) => day.dayOfMonth)).toEqual([20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 1, 2, 3, 4, 5]);
    expect(days[0].weekday).toBe(1);
    expect(days[0].startsAt).toBe(start);
    // 25 October lasts 25 hours in Paris.
    expect(days[5].endsAt - days[5].startsAt).toBe(25 * 3_600_000);
    expect(presentCount(people, days[1])).toBe(2);
  });

  it("has a column per day, a new block each Monday", () => {
    const columns = planningColumns(start, end);

    expect(columns).toHaveLength(17);
    expect(columns.slice(0, 2).map((c) => `${c.top} ${c.bottom}`)).toEqual(["mar 20", "mer 21"]);
    expect(columns.filter((c) => c.group).map((c) => c.bottom)).toEqual(["26", "2"]);
  });

  it("fills a cell for the whole day or a part of it", () => {
    const columns = planningColumns(start, end);
    const carol = people[2].windows;

    expect(planningCell(carol, columns[6])).toEqual({ kind: "absent" });
    expect(planningCell(carol, columns[7])).toMatchObject({ kind: "present", full: false, to: 1 });
    expect(planningCell(carol, columns[8])).toMatchObject({ kind: "present", full: true, from: 0, to: 1 });
    expect(planningCell(people[1].windows, columns[8])).toEqual({ kind: "undeclared" });
  });

  it("draws the whole event at once, today marked", () => {
    const html = renderToStaticMarkup(
      <PresenceTimelineView eventId="e1" now={new Date("2026-10-28T12:00:00+00:00").getTime()} timeline={{ ...LONG, registrants: people }} />,
    );

    expect(html.match(/data-cell="full"/g)).toHaveLength(6);
    expect(html.match(/data-cell="part"/g)).toHaveLength(4);
    expect(html.match(/data-today=""/g)).toHaveLength(1);
    expect(html).not.toContain("Semaine");
  });
});
