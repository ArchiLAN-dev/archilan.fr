import type { ItemEntry } from "./types";

/**
 * Number of item copies held, not of distinct item names.
 *
 * `items_received` / `items_not_received` come from the bridge grouped by item name with a
 * `count`, so their `.length` is a row count: five Pieces of Heart read as one. Surfacing that
 * as "items reçus" under-reports every stacked item.
 */
export function countItems(items: readonly ItemEntry[]): number {
  return items.reduce((total, item) => total + item.count, 0);
}

/**
 * Share of the slot's item pool already received, in percent (0-100).
 *
 * Summing the counts also fixes the denominator: `items_not_received` is `expected - received`,
 * so a partly received name (Piece of Heart 5 of 44) sits in *both* arrays and
 * `received.length + notReceived.length` counted it twice instead of totalling the pool.
 */
export function receivedItemsPercent(
  received: readonly ItemEntry[],
  notReceived: readonly ItemEntry[],
): number {
  const receivedCount = countItems(received);
  const total = receivedCount + countItems(notReceived);
  return total > 0 ? Math.round((receivedCount / total) * 100) : 0;
}
