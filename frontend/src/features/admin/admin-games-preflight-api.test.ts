import { isApworldPreflight } from "./admin-games-api";

describe("isApworldPreflight (story 9.38)", () => {
  it("accepts a complete verdict", () => {
    expect(
      isApworldPreflight({
        status: "failed",
        error: "Exception: boom",
        checkedAt: "2026-07-30T12:00:00Z",
        overridden: false,
        blocks: true,
      }),
    ).toBe(true);
  });

  it("accepts every known status", () => {
    for (const status of ["pending", "passed", "failed", "skipped"]) {
      expect(isApworldPreflight({ status, error: "", checkedAt: "", overridden: false, blocks: false })).toBe(true);
    }
  });

  it("rejects an unknown status", () => {
    expect(isApworldPreflight({ status: "weird", blocks: false })).toBe(false);
  });

  it("rejects null, primitives and incomplete objects", () => {
    expect(isApworldPreflight(null)).toBe(false);
    expect(isApworldPreflight("failed")).toBe(false);
    expect(isApworldPreflight({ status: "failed" })).toBe(false);
    expect(isApworldPreflight({ blocks: true })).toBe(false);
  });
});
