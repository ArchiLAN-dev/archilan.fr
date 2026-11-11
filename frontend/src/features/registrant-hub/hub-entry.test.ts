import type { Registration } from "@/features/auth/auth-api";
import { pickHubEntry } from "./hub-entry";

const now = new Date("2027-06-01T10:00:00Z");

function registration(overrides: Partial<Registration> = {}): Registration {
  return {
    registrationId: "r1",
    eventSlug: "e1",
    eventTitle: "ArchiLAN 4",
    eventStartDate: null,
    registrationStatus: "confirmed",
    slotCount: 1,
    sessionStatus: null,
    eventStartsAt: "2027-06-05T09:00:00+00:00",
    eventEndsAt: "2027-06-06T18:00:00+00:00",
    eventStatus: "published",
    ...overrides,
  };
}

/** Story 53.7: the "Mon event" entry of the account menu. */
describe("pickHubEntry", () => {
  it("leads to an event starting within 14 days", () => {
    expect(pickHubEntry([registration()], now)).toEqual({ href: "/evenements/e1/mon-event", eventTitle: "ArchiLAN 4" });
  });

  it("stays out further ahead, after the event and without dates", () => {
    expect(pickHubEntry([registration({ eventStartsAt: "2027-06-20T09:00:00+00:00", eventEndsAt: "2027-06-21T18:00:00+00:00" })], now)).toBeNull();
    expect(pickHubEntry([registration({ eventStartsAt: "2027-05-20T09:00:00+00:00", eventEndsAt: "2027-05-21T18:00:00+00:00" })], now)).toBeNull();
    expect(pickHubEntry([registration({ eventStartsAt: null })], now)).toBeNull();
    expect(pickHubEntry([registration({ eventStartsAt: undefined, eventEndsAt: undefined, eventStatus: undefined })], now)).toBeNull();
  });

  it("stays out for a cancelled registration, a draft or a closed event", () => {
    expect(pickHubEntry([registration({ registrationStatus: "cancelled" })], now)).toBeNull();
    expect(pickHubEntry([registration({ eventStatus: "draft" })], now)).toBeNull();
    expect(pickHubEntry([registration({ eventStatus: "completed" })], now)).toBeNull();
  });

  it("gives a one-day event without an end its whole day", () => {
    const sameDay = registration({ eventStartsAt: "2027-06-01T08:00:00+00:00", eventEndsAt: null });
    expect(pickHubEntry([sameDay], now)?.href).toBe("/evenements/e1/mon-event");
    expect(pickHubEntry([sameDay], new Date("2027-06-02T09:00:00Z"))).toBeNull();
  });

  it("prefers the event in progress, then the nearest one", () => {
    const later = registration({ eventSlug: "later", eventStartsAt: "2027-06-10T09:00:00+00:00", eventEndsAt: "2027-06-11T18:00:00+00:00" });
    const sooner = registration({ eventSlug: "sooner", eventStartsAt: "2027-06-03T09:00:00+00:00", eventEndsAt: "2027-06-03T18:00:00+00:00" });
    const running = registration({ eventSlug: "running", eventStartsAt: "2027-05-31T09:00:00+00:00", eventEndsAt: "2027-06-02T18:00:00+00:00", eventStatus: "in-progress" });

    expect(pickHubEntry([later, sooner], now)?.href).toBe("/evenements/sooner/mon-event");
    expect(pickHubEntry([later, sooner, running], now)?.href).toBe("/evenements/running/mon-event");
  });
});
