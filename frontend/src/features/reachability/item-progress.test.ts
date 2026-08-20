import { countItems, receivedItemsPercent } from "./item-progress";

import type { ItemEntry } from "./types";

const entry = (id: number, name: string, count: number): ItemEntry => ({ id, name, count });

/**
 * Regression: the slot pages read `items_received.length` as an item count and built the
 * progress ratio from both array lengths. Both are row counts over a list the bridge groups by
 * item name, so a real The Wind Waker slot holding 44 items across 36 names showed "36 items
 * reçus" and 22 % progress instead of 44 and 15 %.
 */
describe("countItems", () => {
  it("counts copies, not rows", () => {
    expect(countItems([entry(1, "Piece of Heart", 5), entry(2, "Boomerang", 1)])).toBe(6);
  });

  it("is 0 on an empty list", () => {
    expect(countItems([])).toBe(0);
  });
});

describe("receivedItemsPercent", () => {
  it("does not count a partly received item name on both sides of the ratio", () => {
    // 5 of 44 Pieces of Heart: the name sits in both arrays, the copies do not.
    const received = [entry(1, "Piece of Heart", 5), entry(2, "Boomerang", 1)];
    const notReceived = [entry(1, "Piece of Heart", 39), entry(3, "Deku Leaf", 5)];

    // 6 received out of 50 expected, not 2 rows out of 4.
    expect(receivedItemsPercent(received, notReceived)).toBe(12);
  });

  it("reaches 100 once nothing is missing", () => {
    expect(receivedItemsPercent([entry(1, "Boomerang", 1)], [])).toBe(100);
  });

  it("is 0 when the slot has no item pool at all", () => {
    expect(receivedItemsPercent([], [])).toBe(0);
  });

  it("is 0 before the first item arrives", () => {
    expect(receivedItemsPercent([], [entry(1, "Boomerang", 1)])).toBe(0);
  });
});
