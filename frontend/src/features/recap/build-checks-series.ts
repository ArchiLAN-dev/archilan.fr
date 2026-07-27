import type { FeedEvent } from "./feed-api";

/**
 * Turns the raw feed into per-minute checks-over-time curves, one per player.
 *
 * Every item event is a **check by its finder** (the sender), so the sender slot is the player and
 * the event's time is when that check happened - this holds in solo too (a self-find is still a find).
 * Time is bucketed to the minute, relative to the first event; each row is the number of checks that
 * player made **in that minute** (0 in a quiet minute), so the curve shows the rhythm - bursts and
 * lulls - rather than a line that only ever rises. Players are keyed and coloured by slot (identity,
 * never rank), so a filter that hides players never repaints the survivors.
 */

export type ChecksPlayer = { key: string; slot: number; name: string; color: string };
export type ChecksRow = { minute: number } & Record<string, number>;
export type ChecksSeries = { players: ChecksPlayer[]; rows: ChecksRow[] };

// The dataviz validated categorical palette, referenced as CSS custom properties so light/dark swap in
// one place (globals.css). Assigned in slot order, never cycled.
const SERIES_COLORS = [
  "var(--chart-series-1)",
  "var(--chart-series-2)",
  "var(--chart-series-3)",
  "var(--chart-series-4)",
  "var(--chart-series-5)",
  "var(--chart-series-6)",
  "var(--chart-series-7)",
  "var(--chart-series-8)",
] as const;

export function buildChecksSeries(events: FeedEvent[]): ChecksSeries {
  const finds = events
    .map((event) => ({ slot: event.sender.slot, name: event.sender.name, at: Date.parse(event.occurredAt) }))
    .filter((f): f is { slot: number; name: string | null; at: number } => f.slot !== null && !Number.isNaN(f.at))
    .sort((a, b) => a.at - b.at);

  if (finds.length === 0) {
    return { players: [], rows: [] };
  }

  const start = finds[0].at;
  const lastMinute = Math.floor((finds[finds.length - 1].at - start) / 60_000);

  // Distinct players in slot order; color assigned by that fixed order (folds past 8 into the 8th hue).
  const slots = [...new Set(finds.map((f) => f.slot))].sort((a, b) => a - b);
  const nameBySlot = new Map<number, string>();
  for (const find of finds) {
    if (!nameBySlot.has(find.slot)) nameBySlot.set(find.slot, find.name ?? `Slot ${find.slot}`);
  }
  const players: ChecksPlayer[] = slots.map((slot, index) => ({
    key: `s${slot}`,
    slot,
    name: nameBySlot.get(slot) ?? `Slot ${slot}`,
    color: SERIES_COLORS[Math.min(index, SERIES_COLORS.length - 1)],
  }));

  // Count checks per player per minute; a minute with none stays 0 (the loop fills every minute).
  const perMinute = new Map<string, number>();
  for (const find of finds) {
    const minute = Math.floor((find.at - start) / 60_000);
    const key = `${minute}:s${find.slot}`;
    perMinute.set(key, (perMinute.get(key) ?? 0) + 1);
  }

  const rows: ChecksRow[] = [];
  for (let minute = 0; minute <= lastMinute; minute += 1) {
    const row: ChecksRow = { minute };
    for (const player of players) {
      row[player.key] = perMinute.get(`${minute}:${player.key}`) ?? 0;
    }
    rows.push(row);
  }

  return { players, rows };
}
