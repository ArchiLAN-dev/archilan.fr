import { isProgressionFind, type FeedEvent } from "./feed-api";

/**
 * Turns the raw feed into per-player curves over time, bucketed by `bucketSeconds`.
 *
 * The default view counts **checks by their finder** (the sender): the sender slot is the player and
 * the event's time is when that check happened - this holds in solo too (a self-find is still a find).
 * Time is bucketed relative to the first event; each row (`t` = the bucket start as a wall-clock epoch
 * in ms) is the number of checks that player made **in that bucket** (0 in a quiet one), so the curve shows the
 * rhythm - bursts and lulls - rather than a line that only ever rises. A finer bucket (e.g. 10 s) shows
 * short bursts a per-minute view flattens. Players are keyed and coloured by slot (identity, never
 * rank), so a filter that hides players never repaints the survivors.
 *
 * Two orthogonal options (story 32.10) change what is counted, never who is who:
 * - `measure`: `"found"` keys on the sender (checks found); `"received"` keys on the receiver (items
 *   received). A self-find counts once on each measure, which is correct.
 * - `mode`: `"interval"` is the per-bucket count above; `"cumulative"` sums it into a running total
 *   for an overall-progress reading.
 *
 * Each row also carries, under `progressionKey`, how many of that bucket's events were *progression*
 * items (AP flags bit 1, story 32.9) - the chart marks those buckets with a dot on the player's line.
 * That count stays per-bucket even in cumulative mode: the dot marks the moment, not a total.
 */

export type ChecksPlayer = { key: string; slot: number; name: string; color: string; progressionKey: string };
export type ChecksRow = { t: number } & Record<string, number>;
export type ChecksSeries = { players: ChecksPlayer[]; rows: ChecksRow[] };
export type ChecksMeasure = "found" | "received";
export type ChecksMode = "interval" | "cumulative";
export type ChecksOptions = { measure?: ChecksMeasure; mode?: ChecksMode };

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

/**
 * Folds a per-player series into one "all players combined" curve (story 32.14): each bucket sums
 * the kept players' counts and progression counts. A sum of running totals is the running total of
 * the sum, so this composes with cumulative mode as-is. `shownSlots` keeps the player filter
 * meaningful - a hidden player stays out of the total. Derived from the split series (same buckets,
 * same gap rows), never rebuilt from events.
 */
export function combineChecksSeries(series: ChecksSeries, shownSlots: ReadonlySet<number>): ChecksSeries {
  const kept = series.players.filter((player) => shownSlots.has(player.slot));
  if (kept.length === 0) {
    return { players: [], rows: [] };
  }
  const combined: ChecksPlayer = {
    key: "all",
    slot: -1,
    name: "Tous les joueurs",
    color: SERIES_COLORS[0],
    progressionKey: "allp",
  };
  const rows = series.rows.map((row) => {
    let total = 0;
    let progression = 0;
    for (const player of kept) {
      total += row[player.key] ?? 0;
      progression += row[player.progressionKey] ?? 0;
    }
    return { t: row.t, [combined.key]: total, [combined.progressionKey]: progression };
  });
  return { players: [combined], rows };
}

/**
 * Slot name -> colour, using the same slot ordering and the same palette as the curves, so a player
 * keeps one visual identity across the whole recap page (exchange diagram and timeline alike).
 * Keyed on the AP slot *name* because that is the only identifier the recap graph and the feed share.
 */
export function slotColorsByName(events: FeedEvent[]): Map<string, string> {
  const nameBySlot = new Map<number, string>();
  for (const event of events) {
    if (event.type !== "item-received") continue;
    const { slot, name } = event.sender;
    if (slot !== null && name !== null && !nameBySlot.has(slot)) nameBySlot.set(slot, name);
  }
  const ordered = [...nameBySlot.entries()].sort((a, b) => a[0] - b[0]);
  return new Map(ordered.map(([, name], index) => [name, SERIES_COLORS[Math.min(index, SERIES_COLORS.length - 1)]]));
}

export function buildChecksSeries(events: FeedEvent[], bucketSeconds = 60, options: ChecksOptions = {}): ChecksSeries {
  const measure: ChecksMeasure = options.measure ?? "found";
  const mode: ChecksMode = options.mode ?? "interval";
  const bucketMs = Math.max(1, bucketSeconds) * 1_000;
  // Curves count item events only - the feed also persists hints and goals (story 32.12), which are
  // log/marker material, not checks (a hint is intent, not a find).
  const finds = events
    .filter((event) => event.type === "item-received")
    .map((event) => {
      const party = measure === "received" ? event.receiver : event.sender;
      return {
        slot: party.slot,
        name: party.name,
        at: Date.parse(event.occurredAt),
        progression: isProgressionFind(event),
      };
    })
    .filter(
      (f): f is { slot: number; name: string | null; at: number; progression: boolean } =>
        f.slot !== null && !Number.isNaN(f.at),
    )
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
    progressionKey: `s${slot}p`,
  }));

  // Count checks (and progression finds) per player per bucket.
  const perBucket = new Map<string, number>();
  const progressionPerBucket = new Map<string, number>();
  for (const find of finds) {
    const bucket = Math.floor((find.at - start) / bucketMs);
    perBucket.set(`${bucket}:s${find.slot}`, (perBucket.get(`${bucket}:s${find.slot}`) ?? 0) + 1);
    if (find.progression) {
      progressionPerBucket.set(`${bucket}:s${find.slot}`, (progressionPerBucket.get(`${bucket}:s${find.slot}`) ?? 0) + 1);
    }
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

  // Cumulative mode sums the per-bucket counts into a running total. Skipped (not emitted) buckets
  // are empty by construction, so accumulating over emitted rows only never drops anything.
  const running = new Map<string, number>();
  const rows: ChecksRow[] = [...emit]
    .sort((a, b) => a - b)
    .map((bucket) => {
      const row: ChecksRow = { t: start + bucket * bucketMs };
      for (const player of players) {
        const count = perBucket.get(`${bucket}:${player.key}`) ?? 0;
        if (mode === "cumulative") {
          const total = (running.get(player.key) ?? 0) + count;
          running.set(player.key, total);
          row[player.key] = total;
        } else {
          row[player.key] = count;
        }
        row[player.progressionKey] = progressionPerBucket.get(`${bucket}:${player.key}`) ?? 0;
      }
      return row;
    });

  return { players, rows };
}
