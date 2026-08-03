import { shouldApplyOwnedFilter } from "./use-steam-coupling";

/**
 * Story 28.11. The rule lives in a pure function precisely so it can be tested: the project runs
 * jest in a node environment with no component-testing stack, so the hook itself is not renderable
 * in a test.
 */
describe("shouldApplyOwnedFilter", () => {
  it("applies the filter when the player explicitly coupled and it worked", () => {
    expect(shouldApplyOwnedFilter("ok", true)).toBe(true);
  });

  it("never applies it on the automatic coupling that runs on every page load", () => {
    // This is the whole point of the story: re-imposing the filter on each visit would fight the
    // player who had just turned it off.
    expect(shouldApplyOwnedFilter("ok", false)).toBe(false);
  });

  it("does not apply it when the coupling failed, explicit or not", () => {
    for (const outcome of ["private_profile", "invalid_input", "steam_error"] as const) {
      expect(shouldApplyOwnedFilter(outcome, true)).toBe(false);
      expect(shouldApplyOwnedFilter(outcome, false)).toBe(false);
    }
  });
});
