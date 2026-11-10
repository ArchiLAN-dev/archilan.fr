import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import {
  absentDuring,
  dayBands,
  halfDaySegments,
  hourTicks,
  isPresentDuring,
  orderedRegistrants,
  presenceSummary,
  presentCount,
  PresenceTimelineView,
  shortMoment,
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
    expect(html).toContain("pas déclaré");
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

  it("writes moments short, in Paris time", () => {
    expect(shortMoment(new Date("2027-06-05T12:00:00+00:00").getTime())).toBe("sam. 14 h");
    expect(shortMoment(new Date("2027-06-05T07:30:00+00:00").getTime())).toBe("sam. 9 h 30");
  });

  it("marks every 6 hours and groups the half-days by day", () => {
    const start = new Date(EVENT.eventStartsAt).getTime();
    const end = new Date(EVENT.eventEndsAt).getTime();
    expect(hourTicks(start, end).map((tick) => tick.label).slice(0, 4)).toEqual(["0 h", "6 h", "12 h", "18 h"]);
    expect(dayBands(segments).map((band) => band.day)).toEqual(["ven. 4", "sam. 5", "dim. 6"]);
  });

  it("names who is missing in a half-day", () => {
    expect(absentDuring(people, segments[2]).map((r) => r.displayName)).toEqual(["Alice"]);
  });

  it("shows the hours in the bars, hatches who said nothing, and never prints ISO dates", () => {
    const html = renderToStaticMarkup(<PresenceTimelineView eventId="e1" timeline={{ ...EVENT, registrants: people }} />);
    expect(html).toContain("sam. 14 h → dim. 12 h");
    expect(html.match(/>pas déclaré</g)).toHaveLength(2);
    expect(html).toContain("Absents : Alice");
    expect(html).toContain("Non déclarés (2)");
    expect(html).not.toMatch(/\d{4}-\d{2}-\d{2}T/);
  });

  it("filters the rows", () => {
    const html = renderToStaticMarkup(<PresenceTimelineView eventId="e1" initialFilter="undeclared" timeline={{ ...EVENT, registrants: people }} />);
    expect(html).not.toContain("/inscriptions/r2");
    expect(html).toContain("/inscriptions/r4");
    expect(html).not.toContain("Aucun inscrit dans ce filtre");
  });

  it("says so when a filter keeps no one, and keeps the filter off the mobile cards", () => {
    const html = renderToStaticMarkup(
      <PresenceTimelineView eventId="e1" initialFilter="undeclared" timeline={{ ...EVENT, registrants: people.filter((r) => r.windows.length > 0) }} />,
    );
    expect(html).toContain("Aucun inscrit dans ce filtre.");
    expect(html).toMatch(/aria-label="Filtrer les inscrits" class="hidden [^"]*md:flex"/);
  });
});
