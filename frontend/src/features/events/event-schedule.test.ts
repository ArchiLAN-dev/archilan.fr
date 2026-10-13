import { dayAndHour, eventSchedule, eventStatusAt, type EventTiming } from "./event-schedule";

const archilan4: EventTiming = {
  status: "published",
  startsAt: "2026-11-21T09:00:00+00:00",
  endsAt: "2026-11-22T17:00:00+00:00",
  registrationOpensAt: "2026-10-18T22:01:00+00:00",
  registrationClosesAt: "2026-10-31T22:59:00+00:00",
  capacity: 48,
  confirmedRegistrations: 12,
};
const at = (iso: string) => new Date(iso);

/** Story 4.23: where the event stands, and its dates in Paris time. */
describe("event schedule", () => {
  it("follows the registration window, the seats and the event's own status", () => {
    expect(eventStatusAt(archilan4, at("2026-10-18T22:00:59+00:00"))).toBe("upcoming");
    expect(eventStatusAt(archilan4, at("2026-10-18T22:01:00+00:00"))).toBe("open");
    expect(eventStatusAt({ ...archilan4, confirmedRegistrations: 48 }, at("2026-10-25T12:00:00+00:00"))).toBe("full");
    expect(eventStatusAt(archilan4, at("2026-10-31T22:59:01+00:00"))).toBe("closed");
    expect(eventStatusAt({ ...archilan4, status: "in-progress" }, at("2026-11-21T10:00:00+00:00"))).toBe("in-progress");
    expect(eventStatusAt({ ...archilan4, status: "completed" }, at("2026-10-25T12:00:00+00:00"))).toBe("completed");
  });

  it("writes the opening in Paris time, summer or winter", () => {
    expect(dayAndHour(archilan4.registrationOpensAt)).toBe("lundi 19 octobre à 00h01");
    expect(dayAndHour(archilan4.registrationClosesAt, true)).toBe("samedi 31 octobre 2026 à 23h59");
  });

  it("writes one day or several", () => {
    expect(eventSchedule(archilan4.startsAt, archilan4.endsAt)).toBe(
      "Du samedi 21 novembre à 10h00 au dimanche 22 novembre 2026 à 18h00",
    );
    expect(eventSchedule("2026-11-21T09:00:00+00:00", "2026-11-21T21:00:00+00:00")).toBe(
      "Samedi 21 novembre 2026, de 10h00 à 22h00",
    );
  });
});
