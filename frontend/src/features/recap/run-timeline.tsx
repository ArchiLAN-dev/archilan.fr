"use client";

import { memo, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ArrowRight, ChevronLeft, ChevronRight, ZoomOut } from "lucide-react";
import type { FeedEvent } from "./feed-api";
import { buildChecksSeries, combineChecksSeries, type ChecksMeasure, type ChecksMode } from "./build-checks-series";
import { buildDrySpells } from "./build-dry-spells";
import { ChecksChart, type ChartGoal } from "./checks-chart";
import { matchesFacet, matchesSearch, normalizeSearch, type LogFacet } from "./log-filters";
import { formatDuration } from "./recap-format";

/** Beyond this many hint markers the axis turns into noise; the surplus is announced, not hidden. */
const MAX_HINT_MARKERS = 24;

/**
 * A player's goal-reached instant (story 32.9). `name` is the AP slot name - the same name the feed
 * events carry as sender, which is how the marker finds its player (and colour) on the chart.
 */
export type GoalMarker = { name: string; at: string };

const MAX_ROWS = 300;

// Values are strings so the bucket picker can ride the same generic Segmented control as the
// other view options (parsed back to seconds on change).
const BUCKETS: readonly { label: string; value: string }[] = [
  { label: "10 s", value: "10" },
  { label: "30 s", value: "30" },
  { label: "1 min", value: "60" },
  { label: "5 min", value: "300" },
];

// View options (story 32.10): what the curve counts, and per-interval vs running total. The defaults
// keep 32.7's burst view (checks found, per interval).
const MEASURES: readonly { label: string; value: ChecksMeasure }[] = [
  { label: "Checks complétés", value: "found" },
  { label: "Objets reçus", value: "received" },
];

const MODES: readonly { label: string; value: ChecksMode }[] = [
  { label: "Par intervalle", value: "interval" },
  { label: "Cumulé", value: "cumulative" },
];

// Story 32.14: one line per player, or every shown player folded into a single combined curve.
type PlayersView = "split" | "combined";
const PLAYERS_VIEWS: readonly { label: string; value: PlayersView }[] = [
  { label: "Séparés", value: "split" },
  { label: "Confondus", value: "combined" },
];

// Exchange-type facet for the log (story 32.11), relative to the shown players: with one player
// isolated, "Envoyés"/"Reçus" read as "what they sent" / "what they received". "Indices" and
// "Objectifs" (story 32.12) isolate the hint/goal rows.
const FACETS: readonly { label: string; value: LogFacet }[] = [
  { label: "Tous", value: "all" },
  { label: "Reçus", value: "received" },
  { label: "Envoyés", value: "sent" },
  { label: "Locaux", value: "local" },
  { label: "Indices", value: "hints" },
  { label: "Objectifs", value: "goals" },
];

/**
 * The run's activity over time: per-player check curves plus a filterable exchange log, built from the
 * persisted feed (story 32.6/32.7). A run spanning several days is paginated by day, so one chart shows
 * one day's rhythm rather than squashing everything onto one axis. Filtering by player hides lines and
 * log rows without repainting the survivors (colour follows the slot). Empty when the run has no item
 * events (a game that produced none, or one still generating).
 *
 * `goals` (story 32.9) mark each player's goal-reached instant on the curve; a marker follows its
 * player through the day pager, the zoom and the player filter (hiding a player hides their marker).
 * When the caller passes no `goals` (the live timeline), they are derived from the feed's goal
 * events (story 32.12) - one source per surface: the recap passes its authoritative podium instants.
 */
export function RunTimeline({ events, goals }: { events: FeedEvent[]; goals?: GoalMarker[] }) {
  const [bucketSeconds, setBucketSeconds] = useState<number>(60);
  const [measure, setMeasure] = useState<ChecksMeasure>("found");
  const [mode, setMode] = useState<ChecksMode>("interval");
  const [playersView, setPlayersView] = useState<PlayersView>("split");
  const [hidden, setHidden] = useState<Set<number>>(new Set());
  const [selectedDay, setSelectedDay] = useState<number | null>(null);
  const [zoom, setZoom] = useState<[number, number] | null>(null);
  // Cross-highlight: the bucket hovered on the chart (highlights matching log rows) and the event time
  // hovered in the log (drops a marker line on the chart).
  const [hoverBucket, setHoverBucket] = useState<number | null>(null);
  const [hoverEventT, setHoverEventT] = useState<number | null>(null);

  // The chart reports the hovered bucket on every mousemove; committing that straight to state
  // re-renders the log per move. Coalesce to at most one commit per animation frame (story 32.13).
  const pendingHover = useRef<number | null>(null);
  const hoverRaf = useRef<number | null>(null);
  const onHoverBucket = useCallback((t: number | null) => {
    pendingHover.current = t;
    if (hoverRaf.current !== null) return;
    hoverRaf.current = requestAnimationFrame(() => {
      hoverRaf.current = null;
      setHoverBucket(pendingHover.current);
    });
  }, []);
  useEffect(
    () => () => {
      if (hoverRaf.current !== null) cancelAnimationFrame(hoverRaf.current);
    },
    [],
  );
  // Log lookup (story 32.11): free-text search + exchange-type facet, applied before the row cap.
  const [search, setSearch] = useState("");
  const [facet, setFacet] = useState<LogFacet>("all");

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
  const series = useMemo(
    () => buildChecksSeries(dayEvents, bucketSeconds, { measure, mode }),
    [dayEvents, bucketSeconds, measure, mode],
  );

  if (series.players.length === 0) {
    return null;
  }

  const shownPlayers = series.players.filter((p) => !hidden.has(p.slot));
  const shownSlots = new Set(shownPlayers.map((p) => p.slot));

  // Combined view (story 32.14): fold the shown players into one curve. Chips, goal markers and
  // the log keep working off the split series - only what the chart draws changes.
  const displayed = playersView === "combined" ? combineChecksSeries(series, shownSlots) : null;
  const chartPlayers = displayed === null ? shownPlayers : displayed.players;
  const chartRows = displayed === null ? series.rows : displayed.rows;

  // Goal markers: the recap passes its podium instants; without them (live), the feed's goal events
  // (story 32.12) provide the same {AP slot name, instant} pairs.
  const goalMarkers: GoalMarker[] =
    goals ??
    events.flatMap((e) => (e.type === "goal" && e.sender.name !== null ? [{ name: e.sender.name, at: e.occurredAt }] : []));

  // Goal markers for the shown day and players, resolved to their series colour by AP slot name.
  const chartGoals: ChartGoal[] = goalMarkers.flatMap((goal) => {
    const at = Date.parse(goal.at);
    if (Number.isNaN(at) || currentDay === null || dayKey(goal.at) !== currentDay) return [];
    const player = shownPlayers.find((p) => p.name === goal.name);
    return player === undefined ? [] : [{ key: player.key, name: player.name, color: player.color, at }];
  });

  // Hint markers (story 32.18). A hint reads "<receiver>'s <item> is at <location> in <sender>'s
  // world", so the player who was looking for something is the RECEIVER - that is who the marker
  // belongs to, not the world the item happened to sit in.
  const allHints = dayEvents.flatMap((e) => {
    if (e.type !== "hint" || e.receiver.name === null) return [];
    const at = Date.parse(e.occurredAt);
    const player = shownPlayers.find((p) => p.name === e.receiver.name);
    return Number.isNaN(at) || player === undefined ? [] : [{ at, color: player.color, key: player.key }];
  });
  // A long run can ask for hints faster than the axis can show them; cap and say so rather than
  // letting a silently truncated axis read as "there were only these".
  const chartHints = allHints.slice(0, MAX_HINT_MARKERS);
  const hiddenHints = allHints.length - chartHints.length;

  // Longest stretch without receiving anything, for the shown players (story 32.18).
  const drySpells = buildDrySpells(dayEvents).filter((spell) => shownSlots.has(spell.slot));

  // The log follows the player filter, the chart zoom, the search and the type facet - all narrow
  // *before* the row cap, so a match late in a long run is still found.
  const normalizedQuery = normalizeSearch(search);
  const shown = dayEvents.filter(
    (e) =>
      e.sender.slot !== null &&
      (zoom === null || within(e.occurredAt, zoom)) &&
      (shownSlots.has(e.sender.slot) || (e.receiver.slot !== null && shownSlots.has(e.receiver.slot))) &&
      matchesFacet(e, facet, shownSlots) &&
      matchesSearch(e, normalizedQuery),
  );
  const rows = shown.slice(-MAX_ROWS).reverse();
  const truncated = shown.length > MAX_ROWS;

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
          L&apos;activité de la partie au fil du temps et le journal des objets. Un point plein marque un objet
          de progression, un trait vertical le moment où un joueur atteint son objectif. Clique un joueur pour
          le masquer.
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

      {/* View options (32.10/32.14): what is counted, how, and per-player vs combined. */}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <Segmented label="Mesure :" onChange={setMeasure} options={MEASURES} value={measure} />
        <Segmented label="Courbe :" onChange={setMode} options={MODES} value={mode} />
        <Segmented label="Joueurs :" onChange={setPlayersView} options={PLAYERS_VIEWS} value={playersView} />
      </div>

      {/* Bucket granularity: a finer bucket surfaces short bursts a per-minute view flattens. */}
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Segmented
          label="Regroupement :"
          onChange={(value) => setBucketSeconds(Number(value))}
          options={BUCKETS}
          value={String(bucketSeconds)}
        />

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

      <ChecksChart
        goals={chartGoals}
        hints={chartHints}
        markerT={hoverEventT}
        measureLabel={MEASURES.find((option) => option.value === measure)?.label ?? ""}
        onHoverBucket={onHoverBucket}
        onZoom={setZoom}
        players={chartPlayers}
        rows={chartRows}
        zoom={zoom}
      />

      {chartHints.length > 0 || drySpells.length > 0 ? (
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-muted-foreground">
          {chartHints.length > 0 ? (
            <span>
              Les traits pointillés marquent les indices demandés
              {hiddenHints > 0 ? ` (${chartHints.length} affichés sur ${allHints.length})` : ""}.
            </span>
          ) : null}
          {drySpells.map((spell) => (
            <span className="inline-flex items-center gap-1.5" key={`dry-${spell.slot}`}>
              <span className="font-semibold text-foreground">{spell.name}</span>
              <span>a attendu {formatDuration(spell.seconds)} sans rien recevoir</span>
            </span>
          ))}
        </div>
      ) : null}

      {/* Log lookup (story 32.11): search + type facet narrow the log before its row cap. */}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <input
          aria-label="Rechercher un objet ou un check"
          className="w-full max-w-xs rounded-lg border border-border bg-surface px-3 py-1 text-foreground placeholder:text-muted-foreground/70 focus:outline-none focus:ring-1 focus:ring-accent-text/50"
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Rechercher un objet ou un check…"
          type="search"
          value={search}
        />
        <Segmented label="Type :" onChange={setFacet} options={FACETS} value={facet} />
      </div>

      <ol className="grid max-h-96 gap-1 overflow-y-auto rounded-lg border border-border bg-surface p-2">
        {truncated ? (
          <li className="px-2 py-1 text-xs text-muted-foreground">
            Les {MAX_ROWS} évènements les plus récents sur {shown.length} correspondants.
          </li>
        ) : null}
        {rows.length === 0 ? (
          <li className="px-2 py-2 text-sm text-muted-foreground">Aucun évènement ne correspond aux filtres.</li>
        ) : null}
        {rows.map((event) => {
          const t = Date.parse(event.occurredAt);
          const highlighted = hoverBucket !== null && t >= hoverBucket && t < hoverBucket + bucketSeconds * 1000;
          return <LogRow event={event} highlighted={highlighted} key={event.id} onHover={setHoverEventT} />;
        })}
      </ol>
    </section>
  );
}

/**
 * One log row, memoized (story 32.13): across a hover-highlight change only the rows entering or
 * leaving the highlight re-render, not all 300. `content-visibility` lets the browser skip layout
 * and paint for off-screen rows - native windowing without a virtualization dependency.
 */
const LogRow = memo(function LogRow({
  event,
  highlighted,
  onHover,
}: {
  event: FeedEvent;
  highlighted: boolean;
  onHover: (t: number | null) => void;
}) {
  const t = Date.parse(event.occurredAt);
  return (
    <li
      className={`flex items-start gap-3 rounded px-2 py-1.5 text-sm [contain-intrinsic-size:auto_34px] [content-visibility:auto] ${highlighted ? "bg-accent-text/15 ring-1 ring-accent-text/40" : "odd:bg-background/40"}`}
      onMouseEnter={() => onHover(t)}
      onMouseLeave={() => onHover(null)}
    >
      <span className="mt-0.5 shrink-0 font-mono text-xs text-muted-foreground">{timeOf(event.occurredAt)}</span>
      <span className="min-w-0 flex-1 text-body-foreground">
        <LogRowContent event={event} />
      </span>
    </li>
  );
});

/**
 * One log row's prose, by event type (story 32.12): an item find (the 32.7 shapes), a hint (intent -
 * where an item waits, badged "Indice") or a goal (completion, badged "Objectif").
 */
function LogRowContent({ event }: { event: FeedEvent }) {
  if (event.type === "goal") {
    return (
      <>
        <span className="mr-1.5 rounded bg-accent/15 px-1.5 py-0.5 text-xs font-semibold text-accent-text">Objectif</span>
        <span className="font-medium text-foreground">{event.sender.name ?? "?"}</span> a atteint son objectif
      </>
    );
  }
  if (event.type === "hint") {
    return (
      <>
        <span className="mr-1.5 rounded bg-accent-warm/15 px-1.5 py-0.5 text-xs font-semibold text-accent-warm">Indice</span>
        <span className="text-accent-text">{event.item.name ?? "objet"}</span>
        {event.receiver.name !== null ? (
          <>
            {" pour "}
            <span className="font-medium text-foreground">{event.receiver.name}</span>
          </>
        ) : null}
        {event.location.name !== null ? (
          <span className="text-muted-foreground">
            {" "}· {event.location.name}
            {event.sender.name !== null ? ` (monde de ${event.sender.name})` : ""}
          </span>
        ) : null}
      </>
    );
  }
  const local = event.sender.slot === event.receiver.slot;
  return (
    <>
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
      {event.location.name !== null ? <span className="text-muted-foreground"> · {event.location.name}</span> : null}
    </>
  );
}

/**
 * One labelled view control, generic over the value union. On a phone (below `sm`) it renders as a
 * native `<select>` - the OS picker never overflows a narrow viewport (story 32.13 mobile pass);
 * from `sm` up it is the segmented button group.
 */
function Segmented<T extends string>({
  label,
  onChange,
  options,
  value,
}: {
  label: string;
  onChange: (value: T) => void;
  options: readonly { label: string; value: T }[];
  value: T;
}) {
  return (
    <div className="flex min-w-0 flex-wrap items-center gap-2">
      <span className="text-muted-foreground">{label}</span>
      <select
        aria-label={label}
        className="rounded-lg border border-border bg-surface px-2 py-1 text-sm text-foreground sm:hidden"
        onChange={(e) => {
          const option = options.find((candidate) => candidate.value === e.target.value);
          if (option !== undefined) onChange(option.value);
        }}
        value={value}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      <div className="hidden max-w-full flex-wrap overflow-hidden rounded-lg border border-border sm:inline-flex">
        {options.map((option) => {
          const on = value === option.value;
          return (
            <button
              aria-pressed={on}
              className={`px-3 py-1 transition-colors ${on ? "bg-accent/15 font-semibold text-accent-text" : "text-muted-foreground hover:text-foreground"}`}
              key={option.value}
              onClick={() => onChange(option.value)}
              type="button"
            >
              {option.label}
            </button>
          );
        })}
      </div>
    </div>
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

/** Whether an event falls inside the zoomed time range [lo, hi] (epoch ms). */
function within(occurredAt: string, [lo, hi]: [number, number]): boolean {
  const t = Date.parse(occurredAt);
  return t >= lo && t <= hi;
}

/** Wall-clock time of day of an event, local time, e.g. "22:30:15". */
function timeOf(occurredAt: string): string {
  const date = new Date(occurredAt);
  return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}
