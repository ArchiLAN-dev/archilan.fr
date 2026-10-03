import { slotPageHref, sourceHomeHref } from "./personal-run-slot-detail-page";

/** Story 41.9: the slot page serves a personal run and an event registration. */
describe("slot page links", () => {
  test("a personal run keeps its routes", () => {
    const source = { kind: "run" as const, runId: "r1" };

    expect(slotPageHref(source, "3")).toBe("/runs/r1/progression/3");
    expect(sourceHomeHref(source)).toBe("/runs/r1");
  });

  test("an event player goes back to their session page", () => {
    const source = { kind: "event" as const, eventSlug: "archilan-3", registrationId: "reg-1" };

    expect(slotPageHref(source, "2")).toBe("/evenements/archilan-3/inscription/reg-1/session/slots/2");
    expect(sourceHomeHref(source)).toBe("/evenements/archilan-3/inscription/reg-1/session");
  });
});
