import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { halfDaySegments, isPresentDuring, presentCount, PresenceTimelineView } from "./admin-presence-timeline";
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
    expect(html).toContain("Présent tout l&#x27;événement");
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
