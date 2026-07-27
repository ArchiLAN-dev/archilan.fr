"use client";

import { useMemo, useState } from "react";
import { ArrowRight } from "lucide-react";
import type { FeedEvent } from "./feed-api";
import { buildChecksSeries } from "./build-checks-series";
import { ChecksChart } from "./checks-chart";

const MAX_ROWS = 300;

const BUCKETS = [
  { label: "10 s", seconds: 10 },
  { label: "30 s", seconds: 30 },
  { label: "1 min", seconds: 60 },
  { label: "5 min", seconds: 300 },
] as const;

/**
 * The run's activity over time: cumulative-checks curves per player, plus a filterable exchange log.
 * Built from the persisted feed (story 32.6/32.7). Filtering by player hides lines and log rows without
 * repainting the survivors (colour follows the slot). Empty when the run has no item events (e.g. a
 * game that produced none, or a run still generating).
 */
export function RunTimeline({ events }: { events: FeedEvent[] }) {
  const [bucketSeconds, setBucketSeconds] = useState<number>(60);
  const series = useMemo(() => buildChecksSeries(events, bucketSeconds), [events, bucketSeconds]);

  const [hidden, setHidden] = useState<Set<number>>(new Set());

  if (series.players.length === 0) {
    return null;
  }

  const shownPlayers = series.players.filter((p) => !hidden.has(p.slot));
  const shownSlots = new Set(shownPlayers.map((p) => p.slot));

  const rows = events
    .filter((e) => e.sender.slot !== null && (shownSlots.has(e.sender.slot) || (e.receiver.slot !== null && shownSlots.has(e.receiver.slot))))
    .slice(-MAX_ROWS)
    .reverse();
  const truncated = events.filter((e) => e.sender.slot !== null).length > MAX_ROWS;

  function toggle(slot: number) {
    setHidden((prev) => {
      const next = new Set(prev);
      if (next.has(slot)) next.delete(slot);
      else next.add(slot);
      return next;
    });
  }

  return (
    <section aria-labelledby="timeline-heading" className="grid gap-4">
      <div className="grid gap-1">
        <h2 className="font-heading text-2xl font-semibold text-foreground" id="timeline-heading">
          Déroulé de la partie
        </h2>
        <p className="text-sm text-muted-foreground">
          Checks trouvés au fil du temps et journal des objets. Clique un joueur pour le masquer.
        </p>
      </div>

      {/* Player filter: same colours as the curves, so identity is never colour-alone. */}
      <div className="flex flex-wrap gap-2">
        {series.players.map((player) => {
          const on = !hidden.has(player.slot);
          return (
            <button
              aria-pressed={on}
              className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm transition-colors ${on ? "border-border text-foreground" : "border-border/50 text-muted-foreground/60"}`}
              key={player.key}
              onClick={() => toggle(player.slot)}
              type="button"
            >
              <span
                aria-hidden="true"
                className="size-2.5 rounded-full"
                style={{ backgroundColor: on ? player.color : "var(--color-border)" }}
              />
              {player.name}
            </button>
          );
        })}
      </div>

      {/* Bucket granularity: a finer bucket surfaces short bursts a per-minute view flattens. */}
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="text-muted-foreground">Regroupement :</span>
        <div className="inline-flex overflow-hidden rounded-lg border border-border">
          {BUCKETS.map((option) => {
            const on = bucketSeconds === option.seconds;
            return (
              <button
                aria-pressed={on}
                className={`px-3 py-1 transition-colors ${on ? "bg-accent/15 font-semibold text-accent-text" : "text-muted-foreground hover:text-foreground"}`}
                key={option.seconds}
                onClick={() => setBucketSeconds(option.seconds)}
                type="button"
              >
                {option.label}
              </button>
            );
          })}
        </div>
      </div>

      <ChecksChart players={shownPlayers} rows={series.rows} />

      <ol className="grid max-h-96 gap-1 overflow-y-auto rounded-lg border border-border bg-surface p-2">
        {truncated ? (
          <li className="px-2 py-1 text-xs text-muted-foreground">
            Les {MAX_ROWS} évènements les plus récents (partie plus longue).
          </li>
        ) : null}
        {rows.map((event) => {
          const local = event.sender.slot === event.receiver.slot;
          return (
            <li className="flex items-start gap-3 rounded px-2 py-1.5 text-sm odd:bg-background/40" key={event.id}>
              <span className="mt-0.5 shrink-0 font-mono text-xs text-muted-foreground">{timeOf(event.occurredAt)}</span>
              <span className="min-w-0 flex-1 text-body-foreground">
                <span className="font-medium text-foreground">{event.sender.name ?? "?"}</span>
                {local ? (
                  <> a trouvé </>
                ) : (
                  <span className="inline-flex items-center gap-1">
                    {" "}
                    <ArrowRight aria-hidden className="size-3 text-muted-foreground" />
                    <span className="font-medium text-foreground">{event.receiver.name ?? "?"}</span>
                    {" : "}
                  </span>
                )}
                <span className="text-accent-text">{event.item.name ?? "objet"}</span>
                {event.location.name ? <span className="text-muted-foreground"> · {event.location.name}</span> : null}
              </span>
            </li>
          );
        })}
      </ol>
    </section>
  );
}

/** Wall-clock time of day of an event, local time, e.g. "22:30:15". */
function timeOf(occurredAt: string): string {
  const date = new Date(occurredAt);
  const two = (n: number) => String(n).padStart(2, "0");
  return `${two(date.getHours())}:${two(date.getMinutes())}:${two(date.getSeconds())}`;
}
