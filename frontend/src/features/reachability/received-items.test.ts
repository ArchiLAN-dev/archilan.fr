import { newlyReceived } from "./received-items";
import type { ReachabilityData } from "./types";

function frame(overrides: Partial<ReachabilityData> = {}): ReachabilityData {
  return {
    game: "Hollow Knight",
    player: "Alice",
    reachable_unchecked: [],
    reachable_checked: [],
    unreachable_unchecked: [],
    checked_unreachable: [],
    items_received: [],
    items_not_received: [],
    counts: { checked: 0, total: 0, reachable_now: 0 },
    ...overrides,
  };
}

describe("story 27.14: received items toasts", () => {
  it("gives one toast per new copy, coloured by the flags each entry carries", () => {
    const previous = new Map([[101, 1]]);
    const data = frame({
      items_received: [
        { id: 101, name: "Mothwing Cloak", count: 2, flags: 1 },
        { id: 102, name: "Geo", count: 1, flags: 0 },
        { id: 103, name: "Trap", count: 1, flags: 4 },
      ],
    });

    expect(newlyReceived(previous, data)).toEqual([
      { name: "Mothwing Cloak", flags: 1 },
      { name: "Geo", flags: 0 },
      { name: "Trap", flags: 4 },
    ]);
  });

  it("falls back on the item placed in a location when an older pass sends no flags", () => {
    const data = frame({
      reachable_checked: [{ id: 501, name: "Crossroads", item: { id: 101, name: "Mothwing Cloak", flags: 1, slot: 1, slot_name: "Alice" } }],
      items_received: [
        { id: 101, name: "Mothwing Cloak", count: 1 },
        { id: 102, name: "Geo", count: 1 },
      ],
    });

    expect(newlyReceived(new Map(), data)).toEqual([
      { name: "Mothwing Cloak", flags: 1 },
      { name: "Geo", flags: 0 },
    ]);
  });

  it("gives nothing when no copy is new", () => {
    expect(newlyReceived(new Map([[101, 2]]), frame({ items_received: [{ id: 101, name: "Mothwing Cloak", count: 2, flags: 1 }] }))).toEqual([]);
  });
});
