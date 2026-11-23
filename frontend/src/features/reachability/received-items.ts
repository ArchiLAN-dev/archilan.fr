import type { ReachabilityData } from "./types";

export type ReceivedToast = { name: string; flags: number };

/**
 * Story 27.14: the items received since the previous frame, one per copy, with the colour of their AP flags.
 *
 * The flags come with each `items_received` entry (bridge and archipelago image from story 27.14 on). An older
 * pass does not send them: the admin's raw result still shows which item sits where, so the flags are read
 * there as before; a player's view has no item in its locations (story 27.13), and the toast stays neutral.
 */
export function newlyReceived(previous: ReadonlyMap<number, number>, data: ReachabilityData): ReceivedToast[] {
  const located = new Map<number, number>();
  for (const check of [...data.reachable_unchecked, ...data.reachable_checked, ...data.unreachable_unchecked]) {
    if (check.item != null) located.set(check.item.id, check.item.flags);
  }

  const toasts: ReceivedToast[] = [];
  for (const item of data.items_received) {
    const flags = typeof item.flags === "number" ? item.flags : (located.get(item.id) ?? 0);
    for (let k = 0; k < item.count - (previous.get(item.id) ?? 0); k++) {
      toasts.push({ name: item.name, flags });
    }
  }

  return toasts;
}

