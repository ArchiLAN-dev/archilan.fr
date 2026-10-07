import { reachableAnswerOf } from "./types";

const data = {
  counts: { checked: 1, total: 10, reachable_now: 3 },
  reachable_unchecked: [],
  reachable_checked: [],
  unreachable_unchecked: [],
  checked_unreachable: [],
  items_received: [],
  items_not_received: [],
};

/** Story 17.28: what a reachability request answered, read once for the three slot pages. */
describe("reachableAnswerOf", () => {
  test("a computation is data", () => {
    expect(reachableAnswerOf(200, { data })).toEqual({ kind: "data", data, computing: false });
  });

  test("202 with the slot's last result shows it while the new one is computed", () => {
    expect(reachableAnswerOf(202, { data, computing: true })).toEqual({ kind: "data", data, computing: true });
  });

  test("202 without any result is « computing »", () => {
    expect(reachableAnswerOf(202, { data: null, computing: true })).toEqual({ kind: "computing" });
  });

  test("the daemon's ready line, or anything that is not a computation, is invalid - never a crash", () => {
    expect(reachableAnswerOf(200, { data: { ready: true, cached: true, player: "kionx_C" } })).toEqual({ kind: "invalid" });
    expect(reachableAnswerOf(200, null)).toEqual({ kind: "invalid" });
  });
});
