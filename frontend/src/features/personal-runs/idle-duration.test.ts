import { formatIdleDuration } from "./idle-duration";

const SINCE = "2026-08-14T10:00:00.000Z";
const at = (minutes: number): number => new Date(SINCE).getTime() + minutes * 60_000;

/**
 * The badge this replaces counted in hours and minutes only, so a run left alone for ten days
 * announced "Inactif depuis 243h 3min" (mesuré en production le 2026-08-24).
 */
describe("formatIdleDuration", () => {
  test("counts in minutes below the hour", () => {
    expect(formatIdleDuration(SINCE, at(0))).toBe("0 min");
    expect(formatIdleDuration(SINCE, at(42))).toBe("42 min");
    expect(formatIdleDuration(SINCE, at(59))).toBe("59 min");
  });

  test("switches to hours, and drops the minutes when there are none", () => {
    expect(formatIdleDuration(SINCE, at(60))).toBe("1 h");
    expect(formatIdleDuration(SINCE, at(185))).toBe("3 h 5 min");
    expect(formatIdleDuration(SINCE, at(23 * 60 + 59))).toBe("23 h 59 min");
  });

  test("switches to days, where the leftover hours stop carrying meaning", () => {
    expect(formatIdleDuration(SINCE, at(24 * 60))).toBe("1 jour");
    expect(formatIdleDuration(SINCE, at(47 * 60))).toBe("1 jour");
    expect(formatIdleDuration(SINCE, at(48 * 60))).toBe("2 jours");
    // The run measured in production: 243 h.
    expect(formatIdleDuration(SINCE, at(243 * 60 + 3))).toBe("10 jours");
  });

  test("gives up rather than printing a negative or unreadable duration", () => {
    expect(formatIdleDuration(SINCE, at(-5))).toBeNull();
    expect(formatIdleDuration("pas une date", at(10))).toBeNull();
  });
});
