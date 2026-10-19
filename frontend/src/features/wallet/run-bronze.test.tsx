import { earningsLabel, isRunBronze, lineLabel } from "./run-bronze";

/** Story 41.38: a player's bronze in one private run. */
describe("run bronze", () => {
  test("says the sources that are on", () => {
    expect(earningsLabel({ start: 20, perCheck: 1, perGoal: 25 })).toBe("20 au départ · +1 par check · +25 au goal");
    expect(earningsLabel({ start: 0, perCheck: 2, perGoal: 0 })).toBe("+2 par check");
    expect(earningsLabel({ start: 0, perCheck: 0, perGoal: 0 })).toBeNull();
  });

  test("grouped checks say how many, other lines their label", () => {
    expect(lineLabel({ reason: "bronze_check", label: "Checks", amount: 37, count: 37, at: "2026-10-18T12:00:00+00:00" })).toBe("37 checks");
    expect(lineLabel({ reason: "bronze_check", label: "Checks", amount: 1, count: 1, at: "2026-10-18T12:00:00+00:00" })).toBe("1 check");
    expect(lineLabel({ reason: "hint_purchase", label: "Hint : Grappin", amount: -20, count: 1, at: "2026-10-18T12:00:00+00:00" })).toBe("Hint : Grappin");
  });

  test("validates the API payload", () => {
    const payload = {
      balance: 23, ended: false, start: 20, perCheck: 1, perGoal: 25, hints: true, itemHintPrice: 20, locationHintPrice: 10, bounties: false,
      history: [{ reason: "bronze_start", label: "Départ", amount: 20, count: 1, at: "2026-10-18T12:00:00+00:00" }],
    };
    expect(isRunBronze(payload)).toBe(true);
    expect(isRunBronze({ ...payload, balance: "23" })).toBe(false);
    expect(isRunBronze({ ...payload, history: [{ reason: "x" }] })).toBe(false);
  });
});
