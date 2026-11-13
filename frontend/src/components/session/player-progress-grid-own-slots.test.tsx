import type { PlayersSlot } from "@/features/overlay/overlay-api";

import { ownSlotsFirst } from "./PlayerProgressGrid";

function entry(index: string, name: string): [string, PlayersSlot] {
  return [
    index,
    { slot_name: name, checks_done: 0, checks_total: 10, items_received: 0, client_status: 20, goal_reached_at: null },
  ];
}

describe("the member's own slots first (v0.66.2)", () => {
  const entries = [entry("1", "3nvieux"), entry("2", "Phil_PR"), entry("3", "Alice"), entry("4", "Phil_MHW")];

  test("own slots go first, each group keeps its order", () => {
    const names = ownSlotsFirst(entries, ["Phil_MHW", "Phil_PR"]).map(([, slot]) => slot.slot_name);

    expect(names).toEqual(["Phil_PR", "Phil_MHW", "3nvieux", "Alice"]);
  });

  test("without own slots (admin, private run) the order does not change", () => {
    expect(ownSlotsFirst(entries, undefined)).toBe(entries);
    expect(ownSlotsFirst(entries, [])).toBe(entries);
  });
});
