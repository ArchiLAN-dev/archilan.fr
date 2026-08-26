import { axisIsUseful, filterHints, isFiltering, NO_FILTERS, sideOf } from "./hint-filters";
import type { HintEntry } from "./types";

const MY_SLOT = 2;

function hint(over: Partial<HintEntry> = {}): HintEntry {
  return {
    receivingPlayer: MY_SLOT,
    receivingPlayerName: "Alice",
    findingPlayer: 3,
    findingPlayerName: "Bob",
    locationId: 100,
    locationName: "Une salle",
    itemId: 200,
    itemName: "Une clé",
    itemFlags: 1,
    entrance: "",
    found: false,
    status: 0,
    statusName: "unspecified",
    ...over,
  };
}

/**
 * Story 9.50: the three axes of the hint list.
 *
 * The ranking already existed and already reached the Archipelago server (stories 9.34/9.35); what
 * was missing was any way to read it back on a run with forty hints.
 */
describe("filterHints", () => {
  const priorityPending = hint({ locationId: 1, statusName: "priority" });
  const avoidPending = hint({ locationId: 2, statusName: "avoid" });
  const priorityFound = hint({ locationId: 3, statusName: "found", found: true });
  const forSomeoneElse = hint({ locationId: 4, statusName: "priority", receivingPlayer: 5, findingPlayer: MY_SLOT });

  const all = [priorityPending, avoidPending, priorityFound, forSomeoneElse];

  test("no filter keeps everything", () => {
    expect(filterHints(all, NO_FILTERS, MY_SLOT)).toHaveLength(4);
    expect(isFiltering(NO_FILTERS)).toBe(false);
  });

  test("the three axes intersect", () => {
    // "What is left for me to look for, in priority" - the question a player actually has mid-run.
    const result = filterHints(all, { state: "pending", priority: "priority", side: "mine" }, MY_SLOT);

    expect(result).toEqual([priorityPending]);
  });

  test("a combination can legitimately match nothing", () => {
    expect(filterHints(all, { state: "found", priority: "avoid", side: "all" }, MY_SLOT)).toEqual([]);
  });

  /** Both sides of a hint call for different moves: advance, or go check for someone else. */
  test("the side filter separates what comes to me from what is hidden in my world", () => {
    const mine = filterHints(all, { ...NO_FILTERS, side: "mine" }, MY_SLOT);
    const inMyWorld = filterHints(all, { ...NO_FILTERS, side: "world" }, MY_SLOT);

    expect(mine.map((h) => h.locationId)).toEqual([1, 2, 3]);
    expect(inMyWorld.map((h) => h.locationId)).toEqual([4]);
  });

  test("a hint that is both sides at once appears under both", () => {
    const own = hint({ locationId: 9, findingPlayer: MY_SLOT });

    expect(filterHints([own], { ...NO_FILTERS, side: "mine" }, MY_SLOT)).toEqual([own]);
    expect(filterHints([own], { ...NO_FILTERS, side: "world" }, MY_SLOT)).toEqual([own]);
  });

  /**
   * Archipelago moves a found hint's status to `found`, which costs it the ranking the player gave
   * it. Asking for "prioritaires" among "trouvés" therefore returns nothing - correct, and exactly
   * the case where the empty-list message has to speak.
   */
  test("a found hint no longer carries the priority it had", () => {
    expect(filterHints(all, { state: "all", priority: "priority", side: "all" }, MY_SLOT))
      .toEqual([priorityPending, forSomeoneElse]);
  });
});

describe("axisIsUseful", () => {
  test("an axis whose hints all share one value is not worth showing", () => {
    const flat = [hint({ locationId: 1 }), hint({ locationId: 2 })];

    expect(axisIsUseful(flat, (h) => h.statusName)).toBe(false);
    expect(axisIsUseful(flat, (h) => sideOf(h, MY_SLOT))).toBe(false);
  });

  test("it is worth showing as soon as the hints differ", () => {
    const mixed = [hint({ statusName: "priority" }), hint({ statusName: "avoid" })];

    expect(axisIsUseful(mixed, (h) => h.statusName)).toBe(true);
  });

  test("an empty list has nothing to sort", () => {
    expect(axisIsUseful([], (h: HintEntry) => h.statusName)).toBe(false);
  });
});
