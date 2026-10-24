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
    expect(reachableAnswerOf(200, { data, spheresVisible: true })).toEqual({ kind: "data", data, computing: false, spheresVisible: true });
  });

  test("202 with the slot's last result shows it while the new one is computed", () => {
    expect(reachableAnswerOf(202, { data, computing: true, spheresVisible: true })).toEqual({ kind: "data", data, computing: true, spheresVisible: true });
  });

  test("202 without any result is « computing »", () => {
    expect(reachableAnswerOf(202, { data: null, computing: true })).toEqual({ kind: "computing" });
  });

  test("the daemon's ready line, or anything that is not a computation, is invalid - never a crash", () => {
    expect(reachableAnswerOf(200, { data: { ready: true, cached: true, player: "kionx_C" } })).toEqual({ kind: "invalid" });
    expect(reachableAnswerOf(200, null)).toEqual({ kind: "invalid" });
  });

  test("story 27.12: the Spheres tab shows only when the API says so", () => {
    expect(reachableAnswerOf(200, { data, spheresVisible: false })).toMatchObject({ spheresVisible: false });
    expect(reachableAnswerOf(200, { data })).toMatchObject({ spheresVisible: false });
  });
});
