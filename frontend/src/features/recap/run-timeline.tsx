"use client";

import { useMemo, useState } from "react";
import { ArrowRight, ChevronLeft, ChevronRight, ZoomOut } from "lucide-react";
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
 * The run's activity over time: per-player check curves plus a filterable exchange log, built from the
 * persisted feed (story 32.6/32.7). A run spanning several days is paginated by day, so one chart shows
 * one day's rhythm rather than squashing everything onto one axis. Filtering by player hides lines and
 * log rows without repainting the survivors (colour follows the slot). Empty when the run has no item
 * events (a game that produced none, or one still generating).
 */
export function RunTimeline({ events }: { events: FeedEvent[] }) {
  const [bucketSeconds, setBucketSeconds] = useState<number>(60);
  const [hidden, setHidden] = useState<Set<number>>(new Set());
  const [selectedDay, setSelectedDay] = useState<number | null>(null);
  const [zoom, setZoom] = useState<[number, number] | null>(null);

  // Days that actually saw item finds, chronological (YYYY-MM-DD sorts as a string).
  const finds = useMemo(() => events.filter((e) => e.sender.slot !== null), [events]);
  const days = useMemo(() => [...new Set(finds.map((e) => dayKey(e.occurredAt)))].sort(), [finds]);

  // Default to the most recent day; clamp in case live events add a day under a stale selection.
  const dayIndex = days.length > 0 ? Math.min(selectedDay ?? days.length - 1, days.length - 1) : 0;
  const currentDay = days[dayIndex] ?? null;

  const dayEvents = useMemo(
    () => (currentDay === null ? [] : events.filter((e) => dayKey(e.occurredAt) === currentDay)),
    [events, currentDay],
  );
  const series = useMemo(() => buildChecksSeries(dayEvents, bucketSeconds), [dayEvents, bucketSeconds]);

  if (series.players.length === 0) {
    return null;
  }

  const shownPlayers = series.players.filter((p) => !hidden.has(p.slot));
  const shownSlots = new Set(shownPlayers.map((p) => p.slot));

  const dayFinds = dayEvents.filter((e) => e.sender.slot !== null);
  const rows = dayFinds
    .filter(
      (e) =>
        (e.sender.slot !== null && shownSlots.has(e.sender.slot)) ||
        (e.receiver.slot !== null && shownSlots.has(e.receiver.slot)),
    )
    .slice(-MAX_ROWS)
    .reverse();
  const truncated = dayFinds.length > MAX_ROWS;

  // Switching day drops any zoom (its t range belongs to the day you left).
  function goToDay(index: number) {
    setSelectedDay(index);
    setZoom(null);
  }

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

      {/* Day pager: only when the run spans more than one day. */}
      {days.length > 1 ? (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-surface px-2 py-1.5">
          <button
            aria-label="Jour précédent"
            className="inline-flex size-8 items-center justify-center rounded text-muted-foreground transition-colors hover:text-foreground disabled:opacity-30"
            disabled={dayIndex === 0}
            onClick={() => goToDay(dayIndex - 1)}
            type="button"
          >
            <ChevronLeft aria-hidden className="size-4" />
          </button>
          <span className="text-sm font-medium text-foreground">
            {currentDay !== null ? formatDay(currentDay) : ""}
            <span className="text-muted-foreground"> · jour {dayIndex + 1}/{days.length}</span>
          </span>
          <button
            aria-label="Jour suivant"
            className="inline-flex size-8 items-center justify-center rounded text-muted-foreground transition-colors hover:text-foreground disabled:opacity-30"
            disabled={dayIndex === days.length - 1}
            onClick={() => goToDay(dayIndex + 1)}
            type="button"
          >
            <ChevronRight aria-hidden className="size-4" />
          </button>
        </div>
      ) : null}

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

        {zoom !== null ? (
          <button
            className="ml-auto inline-flex items-center gap-1.5 rounded border border-border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
            onClick={() => setZoom(null)}
            type="button"
          >
            <ZoomOut aria-hidden className="size-3.5" />
            Réinitialiser le zoom
          </button>
        ) : null}
      </div>

      <ChecksChart onZoom={setZoom} players={shownPlayers} rows={series.rows} zoom={zoom} />

      <ol className="grid max-h-96 gap-1 overflow-y-auto rounded-lg border border-border bg-surface p-2">
        {truncated ? (
          <li className="px-2 py-1 text-xs text-muted-foreground">
            Les {MAX_ROWS} évènements les plus récents de la journée.
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

const pad = (n: number): string => String(n).padStart(2, "0");

/** Local calendar day of an event, as YYYY-MM-DD (sorts chronologically as a string). */
function dayKey(occurredAt: string): string {
  const date = new Date(occurredAt);
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** A YYYY-MM-DD key as a readable local date, e.g. "lundi 28 juillet". */
function formatDay(key: string): string {
  return new Date(`${key}T00:00:00`).toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long" });
}

/** Wall-clock time of day of an event, local time, e.g. "22:30:15". */
function timeOf(occurredAt: string): string {
  const date = new Date(occurredAt);
  return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}
