import type { FeedEvent } from "./feed-api";

export type TopItem = { name: string; count: number };
export type TopItems = { items: TopItem[]; distinctNames: number };

/** AP classification bits: 1 progression, 2 useful, 4 trap; 0 is filler. Null means unknown. */
export type SendQuality = {
  slot: number;
  name: string;
  progression: number;
  useful: number;
  trap: number;
  filler: number;
  unknown: number;
  total: number;
};

/**
 * The items that circulated the most (story 32.19).
 *
 * Events without an item name are excluded rather than grouped under a fake "unknown" label, which
 * would rig the ranking. Ties break on the name so the same run always renders the same list -
 * otherwise the order would depend on feed order and the test would be flaky.
 */
export function buildTopItems(feed: FeedEvent[], limit: number): TopItems {
  const counts = new Map<string, number>();
  for (const event of feed) {
    if (event.type !== "item-received") continue;
    const name = event.item.name;
    if (name === null || name === "") continue;
    counts.set(name, (counts.get(name) ?? 0) + 1);
  }

  const items = [...counts.entries()]
    .map(([name, count]) => ({ count, name }))
    .sort((a, b) => (b.count === a.count ? a.name.localeCompare(b.name, "fr") : b.count - a.count));

  return { distinctNames: items.length, items: items.slice(0, limit) };
}

/**
 * What each player actually sent to the others (story 32.19): fifty filler items and fifty
 * unlocks are the same number and not the same contribution.
 *
 * Counted on sends **to other players**, the convention the whole page uses since story 32.16 -
 * a slot's own finds are not a contribution to anyone. An event whose flags are null is counted as
 * `unknown` rather than silently folded into filler: the bridge that recorded it predates story
 * 32.9 and simply did not say.
 */
export function buildSendQuality(feed: FeedEvent[]): SendQuality[] {
  const bySlot = new Map<number, SendQuality>();

  for (const event of feed) {
    if (event.type !== "item-received") continue;
    const { slot, name } = event.sender;
    if (slot === null || event.receiver.slot === null || event.receiver.slot === slot) continue;

    const entry = bySlot.get(slot) ?? {
      filler: 0,
      name: name ?? `Slot ${slot}`,
      progression: 0,
      slot,
      total: 0,
      trap: 0,
      unknown: 0,
      useful: 0,
    };

    const flags = event.item.flags;
    if (flags === null) entry.unknown += 1;
    else if ((flags & 1) === 1) entry.progression += 1;
    else if ((flags & 4) === 4) entry.trap += 1;
    else if ((flags & 2) === 2) entry.useful += 1;
    else entry.filler += 1;
    entry.total += 1;

    bySlot.set(slot, entry);
  }

  return [...bySlot.values()].sort((a, b) => b.progression - a.progression || a.name.localeCompare(b.name, "fr"));
}
