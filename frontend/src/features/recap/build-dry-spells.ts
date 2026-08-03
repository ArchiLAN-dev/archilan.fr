import type { FeedEvent } from "./feed-api";

/** The longest stretch a player spent receiving nothing, in seconds, with its bounds. */
export type DrySpell = { slot: number; name: string; seconds: number; from: number; to: number };

/**
 * Longest gap between two consecutive items **received** by each player (story 32.18).
 *
 * Bounds, decided once: the wait before a player's first reception and the wait after their last
 * are NOT counted. Both are artefacts of where the run starts and stops rather than something the
 * player lived through - a slot that finishes early would otherwise "hold" a dry spell running to
 * the end of the run.
 *
 * Always keyed on the receiver, never on the sender, and deliberately independent of the chart's
 * "checks found / items received" toggle: a dry spell is a wait endured, not a measure of activity.
 * A player with fewer than two receptions has no measurable gap and is omitted.
 */
export function buildDrySpells(events: FeedEvent[]): DrySpell[] {
  const timesBySlot = new Map<number, number[]>();
  const nameBySlot = new Map<number, string>();

  for (const event of events) {
    if (event.type !== "item-received") continue;
    const { slot, name } = event.receiver;
    const at = Date.parse(event.occurredAt);
    if (slot === null || Number.isNaN(at)) continue;
    timesBySlot.set(slot, [...(timesBySlot.get(slot) ?? []), at]);
    if (name !== null && !nameBySlot.has(slot)) nameBySlot.set(slot, name);
  }

  const spells: DrySpell[] = [];
  for (const [slot, times] of timesBySlot) {
    if (times.length < 2) continue;
    const sorted = [...times].sort((a, b) => a - b);
    let best = { from: sorted[0], gap: 0, to: sorted[0] };
    for (let i = 1; i < sorted.length; i += 1) {
      const gap = sorted[i] - sorted[i - 1];
      if (gap > best.gap) best = { from: sorted[i - 1], gap, to: sorted[i] };
    }
    if (best.gap <= 0) continue;
    spells.push({
      from: best.from,
      name: nameBySlot.get(slot) ?? `Slot ${slot}`,
      seconds: Math.round(best.gap / 1000),
      slot,
      to: best.to,
    });
  }

  return spells.sort((a, b) => b.seconds - a.seconds);
}
