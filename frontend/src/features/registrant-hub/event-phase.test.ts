import { eventPhase, eventPhaseLabel, parisDaysBetween } from "./event-phase";

const event = {
  status: "closed" as const,
  startsAt: "2026-11-21T09:00:00Z",
  endsAt: "2026-11-22T17:00:00Z",
};

/** Story 53.1: where the event stands for its registrants. */
describe("eventPhase", () => {
  it("is before until the start", () => {
    expect(eventPhase(event, new Date("2026-11-21T08:59:59Z"))).toBe("before");
  });

  it("is during right at the start and until the end", () => {
    expect(eventPhase(event, new Date("2026-11-21T09:00:00Z"))).toBe("during");
    expect(eventPhase(event, new Date("2026-11-22T17:00:00Z"))).toBe("during");
  });

  it("is after past the end", () => {
    expect(eventPhase(event, new Date("2026-11-22T17:00:01Z"))).toBe("after");
  });

  it("follows the admin who starts early or closes early", () => {
    expect(eventPhase({ ...event, status: "in-progress" }, new Date("2026-11-20T12:00:00Z"))).toBe("during");
    expect(eventPhase({ ...event, status: "completed" }, new Date("2026-11-22T12:00:00Z"))).toBe("after");
  });

  it("stays before without dates", () => {
    expect(eventPhase({ status: "open" }, new Date("2026-11-21T12:00:00Z"))).toBe("before");
  });
});

describe("eventPhaseLabel", () => {
  it("counts the days in Paris time", () => {
    expect(eventPhaseLabel(event, new Date("2026-11-18T10:00:00Z"))).toBe("J-3");
    expect(eventPhaseLabel(event, new Date("2026-11-20T22:30:00Z"))).toBe("Demain");
    // 00:30 in Paris on the 21st: the day of the event.
    expect(eventPhaseLabel(event, new Date("2026-11-20T23:30:00Z"))).toBe("Aujourd'hui");
  });

  it("names the event in progress and the event over", () => {
    expect(eventPhaseLabel(event, new Date("2026-11-21T12:00:00Z"))).toBe("En cours");
    expect(eventPhaseLabel(event, new Date("2026-11-23T12:00:00Z"))).toBe("Terminé");
  });

  it("says upcoming without a start date", () => {
    expect(eventPhaseLabel({ status: "open" }, new Date("2026-11-21T12:00:00Z"))).toBe("À venir");
  });
});

describe("parisDaysBetween", () => {
  it("crosses the daylight saving change cleanly", () => {
    expect(parisDaysBetween(new Date("2026-10-24T12:00:00Z"), new Date("2026-10-26T12:00:00Z"))).toBe(2);
  });
});
