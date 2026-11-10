"use client";

import Link from "next/link";
import { use, useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { describeWindow, fetchPresenceTimeline, presenceTimelineKey, type PresenceRegistrant, type PresenceTimeline, type PresenceWindow } from "./presence-api";

export type Segment = { startsAt: number; endsAt: number; label: string; day: string; half: "matin" | "après-midi" };

const PARIS_HOUR = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", hourCycle: "h23", timeZone: "Europe/Paris" });
const PARIS_DAY = new Intl.DateTimeFormat("fr-FR", { weekday: "short", day: "numeric", timeZone: "Europe/Paris" });
const HOUR = 3_600_000;

/** The hour of the day in Paris, 0 to 23. */
function parisHour(t: number): number {
  return Number(PARIS_HOUR.formatToParts(new Date(t)).find((part) => part.type === "hour")?.value ?? "0");
}

/** The event cut into half-days (midnight to noon, noon to midnight, Europe/Paris), clipped to its dates. */
export function halfDaySegments(eventStartsAt: string, eventEndsAt: string): Segment[] {
  return halfDaysBetween(new Date(eventStartsAt).getTime(), new Date(eventEndsAt).getTime());
}

/** Story 52.10: the same cut over any span, a week of a long event for one. */
export function halfDaysBetween(start: number, end: number): Segment[] {
  if (!(start < end)) return [];
  const cuts = [start];
  // Paris noon and midnight fall on a whole UTC hour: walk the hours after the start.
  for (let t = Math.ceil(start / HOUR) * HOUR; t < end; t += HOUR) {
    const hour = parisHour(t);
    if ((hour === 0 || hour === 12) && t > start) cuts.push(t);
  }
  cuts.push(end);
  const segments: Segment[] = [];
  for (let i = 1; i < cuts.length; i++) {
    const from = cuts[i - 1];
    const to = cuts[i];
    const day = PARIS_DAY.format(new Date(from));
    const half = parisHour(from) < 12 ? "matin" : "après-midi";
    segments.push({ startsAt: from, endsAt: to, label: `${day} ${half}`, day, half });
  }
  return segments;
}

/** There during the segment: no window at all (there the whole event), or a window that overlaps it. */
export function isPresentDuring(windows: PresenceWindow[], segment: Span): boolean {
  if (windows.length === 0) return true;
  return windows.some((w) => new Date(w.startsAt).getTime() < segment.endsAt && new Date(w.endsAt).getTime() > segment.startsAt);
}

export function presentCount(registrants: PresenceRegistrant[], segment: Span): number {
  return registrants.filter((r) => isPresentDuring(r.windows, segment)).length;
}

/** Story 52.10: past this length, the planning grid has a column per day instead of per half-day. */
export const LONG_EVENT_MS = 4 * 24 * HOUR;

export type Span = { startsAt: number; endsAt: number };
export type Day = Span & { dayOfMonth: number; weekday: number; label: string };

const PARIS_WEEKDAY_INDEX = new Intl.DateTimeFormat("en-US", { weekday: "short", timeZone: "Europe/Paris" });
const PARIS_DAY_OF_MONTH = new Intl.DateTimeFormat("fr-FR", { day: "numeric", timeZone: "Europe/Paris" });
const PARIS_DATE = new Intl.DateTimeFormat("fr-FR", { weekday: "short", day: "numeric", month: "short", timeZone: "Europe/Paris" });
const WEEKDAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

/** 0 for Monday to 6 for Sunday, in Paris. */
function parisWeekday(t: number): number {
  return WEEKDAYS.indexOf(PARIS_WEEKDAY_INDEX.format(new Date(t)));
}

/** The civil days of Paris the span covers, clipped to it: one per date, summer and winter time alike. */
export function parisDays(start: number, end: number): Day[] {
  if (!(start < end)) return [];
  const cuts = [start];
  for (let t = Math.ceil(start / HOUR) * HOUR; t < end; t += HOUR) {
    if (t > start && parisHour(t) === 0) cuts.push(t);
  }
  cuts.push(end);
  const days: Day[] = [];
  for (let i = 1; i < cuts.length; i++) {
    const from = cuts[i - 1];
    days.push({
      startsAt: from,
      endsAt: cuts[i],
      dayOfMonth: Number(PARIS_DAY_OF_MONTH.format(new Date(from))),
      weekday: parisWeekday(from),
      label: PARIS_DATE.format(new Date(from)),
    });
  }
  return days;
}

export type PresenceSummary = { total: number; declared: number; undeclared: number; low: { label: string; count: number } | null };

export function presenceSummary(registrants: PresenceRegistrant[], segments: Segment[]): PresenceSummary {
  const declared = registrants.filter((r) => r.windows.length > 0).length;
  let low: PresenceSummary["low"] = null;
  for (const segment of segments) {
    const count = presentCount(registrants, segment);
    if (low === null || count < low.count) low = { label: segment.label, count };
  }
  return { total: registrants.length, declared, undeclared: registrants.length - declared, low };
}

export type PresenceFilter = "all" | "declared" | "undeclared";

/** Declared first, by arrival; then those who said nothing, by name. */
export function orderedRegistrants(registrants: PresenceRegistrant[], filter: PresenceFilter): PresenceRegistrant[] {
  const arrival = (r: PresenceRegistrant) => Math.min(...r.windows.map((w) => new Date(w.startsAt).getTime()));
  const declared = registrants
    .filter((r) => r.windows.length > 0)
    .sort((a, b) => arrival(a) - arrival(b) || a.displayName.localeCompare(b.displayName, "fr"));
  const undeclared = registrants.filter((r) => r.windows.length === 0).sort((a, b) => a.displayName.localeCompare(b.displayName, "fr"));
  if (filter === "declared") return declared;
  if (filter === "undeclared") return undeclared;
  return [...declared, ...undeclared];
}

/** Story 52.10: the weeks of a span, Monday to Sunday in Paris, each clipped to it. */
export type Week = Span & { label: string };

export function parisWeeks(start: number, end: number): Week[] {
  const weeks: (Span & { first: Day; last: Day })[] = [];
  for (const day of parisDays(start, end)) {
    const current = weeks.at(-1);
    if (current && day.weekday !== 0) {
      current.endsAt = day.endsAt;
      current.last = day;
    } else {
      weeks.push({ startsAt: day.startsAt, endsAt: day.endsAt, first: day, last: day });
    }
  }
  return weeks.map(({ startsAt, endsAt, first, last }) => ({ startsAt, endsAt, label: `${first.label} au ${last.label}` }));
}

/** The week that holds the moment, or the first one. */
export function weekIndexAt(weeks: Week[], t: number | undefined): number {
  if (t === undefined) return 0;
  const index = weeks.findIndex((week) => t >= week.startsAt && t < week.endsAt);
  return index < 0 ? 0 : index;
}

/**
 * Story 52.10: the columns of the planning grid, a day or a half-day each. `group` starts a new block: a Monday, or
 * the morning of a new day.
 */
export type Column = Span & { key: string; top: string; bottom: string; label: string; group: boolean };

const PARIS_WEEKDAY_SHORT = new Intl.DateTimeFormat("fr-FR", { weekday: "short", timeZone: "Europe/Paris" });

export function planningColumns(start: number, end: number, unit: "day" | "half"): Column[] {
  if (unit === "day") {
    return parisDays(start, end).map((day, index) => ({
      startsAt: day.startsAt,
      endsAt: day.endsAt,
      key: String(day.startsAt),
      top: PARIS_WEEKDAY_SHORT.format(new Date(day.startsAt)).replace(".", ""),
      bottom: String(day.dayOfMonth),
      label: day.label,
      group: index > 0 && day.weekday === 0,
    }));
  }
  return halfDaysBetween(start, end).map((segment, index) => ({
    startsAt: segment.startsAt,
    endsAt: segment.endsAt,
    key: String(segment.startsAt),
    top: segment.half === "matin" || index === 0 ? segment.day : "",
    bottom: segment.half === "matin" ? "matin" : "aprèm",
    label: segment.label,
    group: index > 0 && segment.half === "matin",
  }));
}

/** A stretch of presence inside a column, as fractions of it (0 to 1). */
export type CellPart = { from: number; to: number };

/**
 * What one registrant's cell holds: nothing declared, absent, or there for parts of the column. Each declared window
 * is its own part, overlapping ones merged: a gap between two windows (gone from 02:00 to 10:00) stays visible.
 */
export type Cell = { kind: "undeclared" } | { kind: "absent" } | { kind: "present"; parts: CellPart[]; full: boolean; title: string };

export function planningCell(windows: PresenceWindow[], column: Span): Cell {
  if (windows.length === 0) return { kind: "undeclared" };
  const span = column.endsAt - column.startsAt;
  const touching = windows.filter((w) => new Date(w.startsAt).getTime() < column.endsAt && new Date(w.endsAt).getTime() > column.startsAt);
  if (touching.length === 0 || span <= 0) return { kind: "absent" };
  // Within half an hour of an edge, or of the next window, counts as no gap.
  const slack = Math.min(0.02, 1_800_000 / span);
  const clipped = touching
    .map((w) => ({
      from: Math.max(0, (new Date(w.startsAt).getTime() - column.startsAt) / span),
      to: Math.min(1, (new Date(w.endsAt).getTime() - column.startsAt) / span),
    }))
    .sort((a, b) => a.from - b.from);
  const parts: CellPart[] = [];
  for (const part of clipped) {
    const last = parts.at(-1);
    if (last && part.from <= last.to + slack) last.to = Math.max(last.to, part.to);
    else parts.push({ ...part });
  }
  const full = parts.length === 1 && parts[0].from <= slack && parts[0].to >= 1 - slack;
  return { kind: "present", parts, full, title: touching.map(describeWindow).join(" ; ") };
}

function tone(count: number, total: number): string {
  const share = total === 0 ? 1 : count / total;
  if (share >= 0.75) return "bg-success/25 text-foreground";
  if (share >= 0.5) return "bg-accent-warm/25 text-foreground";
  return "bg-danger/25 text-foreground";
}

/** Story 52.2, made readable by stories 52.8 and 52.10: who is at the event when. */
export function AdminPresenceTimelinePage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  // Read once for the « today » column; a refresh moves it.
  const [now] = useState(() => Date.now());
  const { data, isPending } = useQuery({
    queryKey: presenceTimelineKey(eventId),
    queryFn: () => fetchPresenceTimeline(eventId),
    staleTime: 30_000,
    retry: false,
  });

  return (
    <div className="grid gap-6 px-4 pb-10 pt-6 md:px-6">
      <header className="grid gap-1">
        <h1 className="font-heading text-3xl font-bold text-foreground">Qui est là quand</h1>
        <p className="text-sm text-muted-foreground">
          Les inscrits confirmés et les créneaux qu&apos;ils ont déclarés. Un inscrit qui n&apos;a rien déclaré compte comme
          présent tout l&apos;événement.
        </p>
      </header>
      {isPending ? (
        <div className="h-64 w-full animate-pulse rounded-xl bg-surface" />
      ) : data ? (
        <PresenceTimelineView eventId={eventId} now={now} timeline={data} />
      ) : (
        <p className="text-muted-foreground">Les présences n&apos;ont pas pu être chargées.</p>
      )}
    </div>
  );
}

type ViewProps = { eventId: string; timeline: PresenceTimeline; now?: number; initialFilter?: PresenceFilter };

export function PresenceTimelineView({ eventId, timeline, now, initialFilter = "all" }: ViewProps) {
  const [filter, setFilter] = useState<PresenceFilter>(initialFilter);
  const eventStart = new Date(timeline.eventStartsAt).getTime();
  const eventEnd = new Date(timeline.eventEndsAt).getTime();
  // Story 52.10: a long event is read a week at a time (half-days), or whole (a day a column) on demand.
  const long = eventEnd - eventStart > LONG_EVENT_MS;
  const weeks = long ? parisWeeks(eventStart, eventEnd) : [];
  const [weekIndex, setWeekIndex] = useState(() => weekIndexAt(weeks, now));
  const [whole, setWhole] = useState(false);
  const week = long && !whole ? (weeks.at(weekIndex) ?? null) : null;

  if (timeline.registrants.length === 0) return <p className="text-muted-foreground">Aucun inscrit confirmé pour l&apos;instant.</p>;

  const summary = presenceSummary(timeline.registrants, halfDaySegments(timeline.eventStartsAt, timeline.eventEndsAt));
  const columns = week
    ? planningColumns(week.startsAt, week.endsAt, "half")
    : planningColumns(eventStart, eventEnd, long ? "day" : "half");
  const rows = orderedRegistrants(timeline.registrants, filter);
  const filters: { value: PresenceFilter; label: string }[] = [
    { value: "all", label: `Tous (${summary.total})` },
    { value: "declared", label: `Déclarés (${summary.declared})` },
    { value: "undeclared", label: `Non déclarés (${summary.undeclared})` },
  ];

  return (
    <div className="grid gap-5">
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <SummaryTile label="Inscrits" value={String(summary.total)} />
        <SummaryTile label="Ont déclaré" value={String(summary.declared)} />
        <SummaryTile hint={summary.undeclared > 0 ? "comptés présents partout" : undefined} label="Pas déclaré" value={String(summary.undeclared)} />
        {summary.low ? <SummaryTile hint={summary.low.label} label="Creux" value={`${summary.low.count} présents`} /> : null}
      </dl>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div aria-label="Filtrer les inscrits" className="flex flex-wrap gap-2" role="group">
          {filters.map((option) => (
            <button
              aria-pressed={filter === option.value}
              className={`rounded-full border px-3 py-1 text-sm transition-colors ${
                filter === option.value ? "border-accent bg-accent/15 font-semibold text-accent-text" : "border-border text-muted-foreground hover:text-foreground"
              }`}
              key={option.value}
              onClick={() => setFilter(option.value)}
              type="button"
            >
              {option.label}
            </button>
          ))}
        </div>
        <Legend />
      </div>

      {long ? (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm font-semibold text-foreground">{week ? `Semaine du ${week.label}` : "Tout l'événement, un jour par case"}</p>
          <div className="flex flex-wrap gap-2">
            {week ? (
              <>
                <GridButton disabled={weekIndex === 0} label="← Semaine précédente" onClick={() => setWeekIndex(weekIndex - 1)} />
                <GridButton disabled={weekIndex === weeks.length - 1} label="Semaine suivante →" onClick={() => setWeekIndex(weekIndex + 1)} />
              </>
            ) : null}
            <GridButton label={whole ? "Semaine par semaine" : "Tout l'événement"} onClick={() => setWhole(!whole)} pressed={whole} />
          </div>
        </div>
      ) : null}

      <PlanningGrid columns={columns} eventId={eventId} now={now} registrants={timeline.registrants} rows={rows} />
    </div>
  );
}

function GridButton({ label, onClick, disabled = false, pressed }: { label: string; onClick: () => void; disabled?: boolean; pressed?: boolean }) {
  return (
    <button
      aria-pressed={pressed}
      className="rounded-md border border-border px-3 py-1 text-sm text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-border"
      disabled={disabled}
      onClick={onClick}
      type="button"
    >
      {label}
    </button>
  );
}

const HATCHED =
  "bg-[repeating-linear-gradient(135deg,var(--color-border)_0,var(--color-border)_2px,transparent_2px,transparent_7px)]";

function Legend() {
  return (
    <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
      <span className="inline-flex items-center gap-1.5">
        <span aria-hidden="true" className="inline-block size-3.5 rounded-sm bg-accent-text" /> Toute la journée
      </span>
      <span className="inline-flex items-center gap-1.5">
        <span aria-hidden="true" className="relative inline-block size-3.5 rounded-sm bg-surface-2">
          <span className="absolute inset-y-0 right-0 w-1/2 rounded-sm bg-accent-text/55" />
        </span>{" "}
        Une partie
      </span>
      <span className="inline-flex items-center gap-1.5">
        <span aria-hidden="true" className={`inline-block size-3.5 rounded-sm border border-dashed border-border ${HATCHED}`} /> Rien déclaré (compté
        présent)
      </span>
    </p>
  );
}

function SummaryTile({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-lg border border-border bg-surface p-3">
      <dt className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{label}</dt>
      <dd className="mt-1 font-heading text-2xl font-bold text-foreground">{value}</dd>
      {hint ? <dd className="text-xs text-muted-foreground">{hint}</dd> : null}
    </div>
  );
}

type GridProps = { eventId: string; columns: Column[]; rows: PresenceRegistrant[]; registrants: PresenceRegistrant[]; now?: number };

/**
 * Story 52.10: the planning grid. A row per registrant, a cell per day (or half-day): full when there the whole of
 * it, a part filled when there for a part, hatched when nothing was declared. The count of those there heads each
 * column; the exact hours are in each cell's title.
 */
function PlanningGrid({ eventId, columns, rows, registrants, now }: GridProps) {
  const total = registrants.length;
  const edge = (column: Column) => (column.group ? "border-l-2 border-l-border" : "");

  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-surface">
      <table className="w-full border-separate border-spacing-0 text-xs">
        <thead>
          <tr>
            <th className="sticky left-0 z-20 min-w-36 bg-surface px-3 text-left" rowSpan={2} scope="col">
              <span className="sr-only">Inscrit</span>
            </th>
            {columns.map((column) => {
              const today = now !== undefined && now >= column.startsAt && now < column.endsAt;
              return (
                <th
                  className={`min-w-8 px-0.5 pt-2 text-center font-normal capitalize ${today ? "text-accent-text" : "text-muted-foreground"} ${edge(column)}`}
                  key={column.key}
                  scope="col"
                  title={column.label}
                >
                  {column.top}
                </th>
              );
            })}
          </tr>
          <tr>
            {columns.map((column) => {
              const today = now !== undefined && now >= column.startsAt && now < column.endsAt;
              return (
                <th
                  className={`px-0.5 pb-1 text-center text-sm ${today ? "font-bold text-accent-text" : "font-semibold text-foreground"} ${edge(column)}`}
                  data-today={today ? "" : undefined}
                  key={column.key}
                  scope="col"
                >
                  {column.bottom}
                </th>
              );
            })}
          </tr>
          <tr>
            <th className="sticky left-0 z-20 border-b border-border bg-surface px-3 py-1 text-left font-semibold text-muted-foreground" scope="row">
              Présents
            </th>
            {columns.map((column) => {
              const count = presentCount(registrants, column);
              return (
                <td className={`border-b border-border px-0.5 py-1 text-center ${edge(column)}`} key={column.key} title={`${column.label} : ${count} présents sur ${total}`}>
                  <span className={`inline-block min-w-7 rounded px-1 py-0.5 text-sm font-semibold ${tone(count, total)}`}>{count}</span>
                </td>
              );
            })}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td className="px-3 py-6 text-center text-sm text-muted-foreground" colSpan={columns.length + 1}>
                Aucun inscrit dans ce filtre.
              </td>
            </tr>
          ) : null}
          {rows.map((registrant) => (
            <tr className="group" key={registrant.registrationId}>
              <th className="sticky left-0 z-10 border-b border-border/50 bg-surface px-3 py-0.5 text-left font-normal group-hover:bg-surface-2" scope="row">
                <Link
                  className="block max-w-44 truncate text-sm text-foreground hover:text-accent-text hover:underline"
                  href={`/admin/evenements/${eventId}/inscriptions/${registrant.registrationId}`}
                >
                  {registrant.displayName}
                </Link>
              </th>
              {columns.map((column) => (
                <td className={`border-b border-border/50 p-0.5 group-hover:bg-surface-2/60 ${edge(column)}`} key={column.key}>
                  <PlanningCell cell={planningCell(registrant.windows, column)} column={column} name={registrant.displayName} />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PlanningCell({ cell, column, name }: { cell: Cell; column: Column; name: string }) {
  if (cell.kind === "undeclared") {
    return <span aria-label={`${name}, ${column.label} : rien déclaré`} className={`block h-6 rounded-sm ${HATCHED}`} role="img" title="Rien déclaré, compté présent" />;
  }
  if (cell.kind === "absent") {
    return <span aria-label={`${name}, ${column.label} : absent`} className="block h-6" role="img" />;
  }
  return (
    <span aria-label={`${name}, ${column.label} : ${cell.title}`} className="relative block h-6 overflow-hidden rounded-sm bg-surface-2" data-cell={cell.full ? "full" : "part"} role="img" title={cell.title}>
      {cell.parts.map((part) => (
        <span
          className={`absolute inset-y-0 rounded-sm ${cell.full ? "bg-accent-text" : "bg-accent-text/55"}`}
          key={part.from}
          style={cell.full ? { left: 0, width: "100%" } : { left: `${part.from * 100}%`, width: `${Math.max(8, (part.to - part.from) * 100)}%` }}
        />
      ))}
    </span>
  );
}
