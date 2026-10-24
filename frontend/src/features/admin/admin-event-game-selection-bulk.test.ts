import { withAllFiltered } from "./admin-event-game-selection-page";

describe("event catalogue bulk add (story 3.14)", () => {
  it("adds every filtered game, whatever the per-registrant slot limit", () => {
    const filtered = Array.from({ length: 12 }, (_, i) => ({ id: `g${i}` }));

    const next = withAllFiltered(new Set(["g0", "other"]), filtered);

    expect(next.size).toBe(13);
    expect(next.has("other")).toBe(true);
  });

  it("leaves the current selection untouched", () => {
    const selected = new Set(["a"]);

    withAllFiltered(selected, [{ id: "b" }]);

    expect([...selected]).toEqual(["a"]);
  });
});
