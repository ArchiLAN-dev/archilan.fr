import type { FeedEvent } from "./feed-api";

/**
 * Turns the raw feed into checks-over-time curves, one per player, bucketed by `bucketSeconds`.
 *
 * Every item event is a **check by its finder** (the sender), so the sender slot is the player and
 * the event's time is when that check happened - this holds in solo too (a self-find is still a find).
 * Time is bucketed relative to the first event; each row (`t` = the bucket start as a wall-clock epoch
 * in ms) is the number of checks that player made **in that bucket** (0 in a quiet one), so the curve shows the
 * rhythm - bursts and lulls - rather than a line that only ever rises. A finer bucket (e.g. 10 s) shows
 * short bursts a per-minute view flattens. Players are keyed and coloured by slot (identity, never
 * rank), so a filter that hides players never repaints the survivors.
 */

export type ChecksPlayer = { key: string; slot: number; name: string; color: string };
export type ChecksRow = { t: number } & Record<string, number>;
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

export function buildChecksSeries(events: FeedEvent[], bucketSeconds = 60): ChecksSeries {
  const bucketMs = Math.max(1, bucketSeconds) * 1_000;
  const finds = events
    .map((event) => ({ slot: event.sender.slot, name: event.sender.name, at: Date.parse(event.occurredAt) }))
    .filter((f): f is { slot: number; name: string | null; at: number } => f.slot !== null && !Number.isNaN(f.at))
    .sort((a, b) => a.at - b.at);

  if (finds.length === 0) {
    return { players: [], rows: [] };
  }

  const start = finds[0].at;

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

  // Count checks per player per bucket.
  const perBucket = new Map<string, number>();
  for (const find of finds) {
    const bucket = Math.floor((find.at - start) / bucketMs);
    perBucket.set(`${bucket}:s${find.slot}`, (perBucket.get(`${bucket}:s${find.slot}`) ?? 0) + 1);
  }

  // Emit only buckets with activity, plus the empty bucket on each side of a gap - so a lull shows the
  // line dropping to 0 without generating a row per empty bucket across a long idle stretch (a run
  // paused overnight or spanning days would otherwise explode into thousands of zero rows).
  const activeBuckets = [...new Set(finds.map((f) => Math.floor((f.at - start) / bucketMs)))].sort((a, b) => a - b);
  const emit = new Set<number>();
  for (let i = 0; i < activeBuckets.length; i += 1) {
    const bucket = activeBuckets[i];
    emit.add(bucket);
    const previous = i > 0 ? activeBuckets[i - 1] : null;
    if (previous !== null && bucket - previous > 1) {
      emit.add(previous + 1);
      emit.add(bucket - 1);
    }
  }

  const rows: ChecksRow[] = [...emit]
    .sort((a, b) => a - b)
    .map((bucket) => {
      const row: ChecksRow = { t: start + bucket * bucketMs };
      for (const player of players) {
        row[player.key] = perBucket.get(`${bucket}:${player.key}`) ?? 0;
      }
      return row;
    });

  return { players, rows };
}
