import { FANFARE_GAIN } from "./fanfares";

// Story 16.25: the goal fanfare was far too loud at 0.30; the preview (0.22) did not even match it.
describe("fanfare volume", () => {
  it("plays well under the old level", () => {
    expect(FANFARE_GAIN).toBeGreaterThan(0);
    expect(FANFARE_GAIN).toBeLessThanOrEqual(0.12);
  });
});
