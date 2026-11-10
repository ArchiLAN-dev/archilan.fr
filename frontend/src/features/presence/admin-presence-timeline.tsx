"use client";

import Link from "next/link";
import { use, useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { describeWindow, fetchPresenceTimeline, presenceTimelineKey, type PresenceRegistrant, type PresenceTimeline, type PresenceWindow } from "./presence-api";

export type Segment = { startsAt: number; endsAt: number; label: string; day: string; half: "matin" | "après-midi" };

const PARIS_HOUR = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", hourCycle: "h23", timeZone: "Europe/Paris" });
const PARIS_DAY = new Intl.DateTimeFormat("fr-FR", { weekday: "short", day: "numeric", timeZone: "Europe/Paris" });
const PARIS_WEEKDAY = new Intl.DateTimeFormat("fr-FR", { weekday: "short", timeZone: "Europe/Paris" });
const PARIS_TIME = new Intl.DateTimeFormat("fr-FR", { hour: "numeric", minute: "2-digit", hourCycle: "h23", timeZone: "Europe/Paris" });
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

/** Story 52.10: past this length, the timeline shows one week at a time under a calendar of the whole event. */
export const LONG_EVENT_MS = 4 * 24 * HOUR;

export type Span = { startsAt: number; endsAt: number };
export type Day = Span & { dayOfMonth: number; weekday: number; label: string };
export type Week = Span & { days: Day[]; label: string };

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

/** The weeks of the span, Monday to Sunday, each clipped to it. */
export function parisWeeks(start: number, end: number): Week[] {
  const weeks: Week[] = [];
  for (const day of parisDays(start, end)) {
    const last = weeks.at(-1);
    if (last && day.weekday !== 0) {
      last.days.push(day);
      last.endsAt = day.endsAt;
    } else {
      weeks.push({ startsAt: day.startsAt, endsAt: day.endsAt, days: [day], label: "" });
    }
  }
  for (const week of weeks) {
    week.label = `${week.days[0].label} au ${week.days[week.days.length - 1].label}`;
  }
  return weeks;
}

/** The week that holds the moment, or the first one. */
export function weekIndexAt(weeks: Week[], t: number | undefined): number {
  if (t === undefined) return 0;
  const index = weeks.findIndex((week) => t >= week.startsAt && t < week.endsAt);
  return index < 0 ? 0 : index;
}

/** Story 52.8: « sam. 14 h », « dim. 9 h 30 » - a moment of the event, short enough to sit in a bar. */
export function shortMoment(t: number): string {
  const [hours, minutes] = PARIS_TIME.format(new Date(t)).split(":");
  return `${PARIS_WEEKDAY.format(new Date(t))} ${Number(hours)} h${minutes === "00" ? "" : ` ${minutes}`}`;
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

/** Every 6 hours of Paris time strictly inside the event. */
export function hourTicks(start: number, end: number): { at: number; label: string }[] {
  const ticks: { at: number; label: string }[] = [];
  for (let t = Math.ceil(start / HOUR) * HOUR; t < end; t += HOUR) {
    const hour = parisHour(t);
    if (t > start && hour % 6 === 0) ticks.push({ at: t, label: `${hour} h` });
  }
  return ticks;
}

/** The days of the event, each spanning its half-days. */
export function dayBands(segments: Segment[]): { day: string; startsAt: number; endsAt: number }[] {
  const bands: { day: string; startsAt: number; endsAt: number }[] = [];
  for (const segment of segments) {
    const last = bands.at(-1);
    if (last && last.day === segment.day) last.endsAt = segment.endsAt;
    else bands.push({ day: segment.day, startsAt: segment.startsAt, endsAt: segment.endsAt });
  }
  return bands;
}

/** Declared, and not there during the segment. */
export function absentDuring(registrants: PresenceRegistrant[], segment: Segment): PresenceRegistrant[] {
  return orderedRegistrants(registrants, "declared").filter((r) => !isPresentDuring(r.windows, segment));
}

function tone(count: number, total: number): string {
  const share = total === 0 ? 1 : count / total;
  if (share >= 0.75) return "bg-success/60";
  if (share >= 0.5) return "bg-accent-warm/60";
  return "bg-danger/60";
}

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

/** Story 52.2, made readable by story 52.8: who is at the event when. */
export function AdminPresenceTimelinePage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  // Read once for the « now » marker; a refresh moves it.
  const [now] = useState(() => Date.now());
  const { data, isPending } = useQuery({
    queryKey: presenceTimelineKey(eventId),
    queryFn: () => fetchPresenceTimeline(eventId),
    staleTime: 30_000,
    retry: false,
  });

  return (
    <div className="grid gap-6 pb-10 lg:pt-4">
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
        <p className="text-muted-foreground">La frise n&apos;a pas pu être chargée.</p>
      )}
    </div>
  );
}

type ViewProps = { eventId: string; timeline: PresenceTimeline; now?: number; initialFilter?: PresenceFilter };

export function PresenceTimelineView({ eventId, timeline, now, initialFilter = "all" }: ViewProps) {
  const [filter, setFilter] = useState<PresenceFilter>(initialFilter);
  const eventStart = new Date(timeline.eventStartsAt).getTime();
  const eventEnd = new Date(timeline.eventEndsAt).getTime();
  // Story 52.10: a long event is read one week at a time, under a calendar of all its days.
  const long = eventEnd - eventStart > LONG_EVENT_MS;
  const weeks = long ? parisWeeks(eventStart, eventEnd) : [];
  const [weekIndex, setWeekIndex] = useState(() => weekIndexAt(weeks, now));
  const week = weeks.at(weekIndex) ?? null;
  const range: Span = week ?? { startsAt: eventStart, endsAt: eventEnd };
  const segments = halfDaysBetween(range.startsAt, range.endsAt);

  if (timeline.registrants.length === 0) return <p className="text-muted-foreground">Aucun inscrit confirmé pour l&apos;instant.</p>;

  const summary = presenceSummary(timeline.registrants, halfDaySegments(timeline.eventStartsAt, timeline.eventEndsAt));
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

      {long ? <PresenceCalendar onSelect={setWeekIndex} registrants={timeline.registrants} selected={weekIndex} weeks={weeks} /> : null}
      {week ? (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm font-semibold text-foreground">Semaine du {week.label}</p>
          <div className="flex gap-2">
            <WeekButton disabled={weekIndex === 0} label="← Semaine précédente" onClick={() => setWeekIndex(weekIndex - 1)} />
            <WeekButton disabled={weekIndex === weeks.length - 1} label="Semaine suivante →" onClick={() => setWeekIndex(weekIndex + 1)} />
          </div>
        </div>
      ) : null}

      {/* The filter acts on the timeline rows only, so it is shown with the timeline, not over the mobile cards. */}
      <div aria-label="Filtrer les inscrits" className="hidden flex-wrap gap-2 md:flex" role="group">
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

      <div className="hidden md:block">
        <Timeline eventId={eventId} now={now} range={range} rows={rows} segments={segments} timeline={timeline} />
      </div>
      <div className="md:hidden">
        <HalfDayCards segments={segments} timeline={timeline} />
      </div>

      <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
        <span className="inline-flex items-center gap-1.5">
          <span aria-hidden="true" className="inline-block h-2.5 w-5 rounded-sm bg-accent/70" /> Créneau déclaré
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span aria-hidden="true" className={`inline-block h-2.5 w-5 rounded-sm ${HATCHED}`} /> Pas déclaré (compté présent)
        </span>
      </p>
    </div>
  );
}

function WeekButton({ label, disabled, onClick }: { label: string; disabled: boolean; onClick: () => void }) {
  return (
    <button
      className="rounded-md border border-border px-3 py-1 text-sm text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-border"
      disabled={disabled}
      onClick={onClick}
      type="button"
    >
      {label}
    </button>
  );
}

const CALENDAR_HEAD = ["lun.", "mar.", "mer.", "jeu.", "ven.", "sam.", "dim."];

type CalendarProps = { weeks: Week[]; registrants: PresenceRegistrant[]; selected: number; onSelect: (index: number) => void };

/** Story 52.10: every day of a long event, with how many are there; a week's row picks the week shown below. */
export function PresenceCalendar({ weeks, registrants, selected, onSelect }: CalendarProps) {
  const total = registrants.length;

  return (
    <section aria-label="Calendrier des présences" className="grid w-full max-w-2xl gap-1 rounded-lg border border-border bg-surface p-3">
      <div aria-hidden="true" className="grid grid-cols-7 gap-1 px-1 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {CALENDAR_HEAD.map((day) => (
          <span key={day}>{day}</span>
        ))}
      </div>
      {weeks.map((week, index) => (
        <button
          aria-label={`Semaine du ${week.label}`}
          aria-pressed={index === selected}
          className={`grid grid-cols-7 gap-1 rounded-md p-1 text-left transition-colors ${index === selected ? "bg-accent/10 ring-2 ring-accent" : "hover:bg-surface-2"}`}
          key={week.startsAt}
          onClick={() => onSelect(index)}
          type="button"
        >
          {CALENDAR_HEAD.map((head, weekday) => {
            const day = week.days.find((candidate) => candidate.weekday === weekday);
            if (!day) return <span aria-hidden="true" className="min-h-11" data-day="out" key={head} />;
            const count = presentCount(registrants, day);
            return (
              <span
                className="grid min-h-11 content-between rounded border border-border/60 bg-background/40 px-1.5 py-1"
                data-day="in"
                key={head}
                title={`${day.label} : ${count} présents sur ${total}`}
              >
                <span className="text-[11px] text-muted-foreground">{day.dayOfMonth}</span>
                <span className="flex items-center gap-1">
                  <span aria-hidden="true" className={`size-2 shrink-0 rounded-full ${tone(count, total)}`} />
                  <span className="text-sm font-semibold text-foreground">{count}</span>
                </span>
              </span>
            );
          })}
        </button>
      ))}
    </section>
  );
}

const HATCHED =
  "bg-[repeating-linear-gradient(135deg,var(--color-surface-2)_0,var(--color-surface-2)_6px,transparent_6px,transparent_12px)] border border-dashed border-border";

function SummaryTile({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-lg border border-border bg-surface p-3">
      <dt className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{label}</dt>
      <dd className="mt-1 font-heading text-2xl font-bold text-foreground">{value}</dd>
      {hint ? <dd className="text-xs text-muted-foreground">{hint}</dd> : null}
    </div>
  );
}

function Timeline({
  eventId,
  now,
  range,
  rows,
  segments,
  timeline,
}: {
  eventId: string;
  now?: number;
  range: Span;
  rows: PresenceRegistrant[];
  segments: Segment[];
  timeline: PresenceTimeline;
}) {
  // Story 52.10: the span shown, the whole event or one week of a long one.
  const start = range.startsAt;
  const end = range.endsAt;
  const span = end - start;
  const pct = (t: number) => Math.min(100, Math.max(0, ((t - start) / span) * 100));
  const total = timeline.registrants.length;
  const ticks = hourTicks(start, end);
  const showNow = now !== undefined && now > start && now < end;

  return (
    <div className="rounded-lg border border-border bg-surface">
      <div className="grid grid-cols-[11rem_minmax(0,1fr)]">
        {/* Axis: days, then half-days with who is there. */}
        <div className="border-b border-border" />
        <div className="relative h-7 border-b border-border">
          {dayBands(segments).map((band) => (
            <span
              className="absolute inset-y-0 flex items-center justify-center border-l border-border text-xs font-semibold capitalize text-foreground first:border-l-0"
              key={band.startsAt}
              style={{ left: `${pct(band.startsAt)}%`, width: `${pct(band.endsAt) - pct(band.startsAt)}%` }}
            >
              {band.day}
            </span>
          ))}
        </div>

        <div className="flex items-end border-b border-border px-3 pb-2 text-xs text-muted-foreground">Présents</div>
        <div className="relative h-20 border-b border-border">
          {segments.map((segment) => {
            const count = presentCount(timeline.registrants, segment);
            return (
              <div
                className="absolute inset-y-0 flex flex-col justify-end border-l border-border px-1 pb-1"
                key={segment.startsAt}
                style={{ left: `${pct(segment.startsAt)}%`, width: `${pct(segment.endsAt) - pct(segment.startsAt)}%` }}
                title={`${segment.label} : ${count} présents sur ${total}`}
              >
                <span className="text-center text-xs font-semibold text-foreground">{count}</span>
                <span className={`mx-auto w-full max-w-10 rounded-t ${tone(count, total)}`} style={{ height: `${Math.max(4, (count / Math.max(1, total)) * 40)}px` }} />
                <span className="truncate text-center text-[10px] text-muted-foreground">{segment.half}</span>
              </div>
            );
          })}
        </div>

        {/* Rows, over a grid that runs through them all. */}
        <div className="border-b border-border px-3 py-1 text-[11px] text-muted-foreground">Heure (Paris)</div>
        <div className="relative h-6 border-b border-border">
          {ticks.map((tick) => (
            <span className="absolute top-1 -translate-x-1/2 text-[10px] text-muted-foreground" key={tick.at} style={{ left: `${pct(tick.at)}%` }}>
              {tick.label}
            </span>
          ))}
        </div>

        {rows.length === 0 ? (
          <p className="col-span-2 px-3 py-6 text-center text-sm text-muted-foreground">Aucun inscrit dans ce filtre.</p>
        ) : null}
        <div className="contents">
          <ul className="divide-y divide-border">
            {rows.map((registrant) => (
              <li className="flex h-10 items-center gap-2 px-3" key={registrant.registrationId}>
                <span aria-hidden="true" className="grid size-6 shrink-0 place-items-center rounded-full bg-surface-2 text-[10px] font-semibold text-muted-foreground">
                  {initials(registrant.displayName)}
                </span>
                <Link
                  className="truncate text-sm text-foreground hover:text-accent-text hover:underline"
                  href={`/admin/evenements/${eventId}/inscriptions/${registrant.registrationId}`}
                >
                  {registrant.displayName}
                </Link>
              </li>
            ))}
          </ul>
          <div className="relative">
            {/* The grid: half-day lines, stronger at midnight, and the « now » marker. */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0">
              {segments.slice(1).map((segment) => (
                <span
                  className={`absolute inset-y-0 border-l ${segment.half === "matin" ? "border-border" : "border-dashed border-border/60"}`}
                  key={segment.startsAt}
                  style={{ left: `${pct(segment.startsAt)}%` }}
                />
              ))}
              {showNow && now !== undefined ? <span className="absolute inset-y-0 border-l-2 border-accent-warm" style={{ left: `${pct(now)}%` }} title="Maintenant" /> : null}
            </div>
            <ul className="divide-y divide-border">
              {rows.map((registrant) => (
                <li className="relative h-10" key={registrant.registrationId}>
                  {registrant.windows.length > 0 && !isPresentDuring(registrant.windows, range) ? (
                    <span className="absolute inset-x-1 top-2 bottom-2 flex items-center px-2 text-[11px] text-muted-foreground">pas là cette semaine</span>
                  ) : registrant.windows.length === 0 ? (
                    <span
                      aria-label={`${registrant.displayName} : pas déclaré, compté présent tout l'événement`}
                      className={`absolute inset-x-1 top-2 bottom-2 flex items-center justify-center rounded text-[11px] text-muted-foreground ${HATCHED}`}
                      role="img"
                    >
                      pas déclaré
                    </span>
                  ) : (
                    registrant.windows.map((w) => {
                      const from = new Date(w.startsAt).getTime();
                      const to = new Date(w.endsAt).getTime();
                      // A window outside the span shown has no bar; one across its edge is cut there, its text unchanged.
                      if (to <= start || from >= end) return null;
                      return (
                        <span
                          aria-label={`${registrant.displayName} : ${describeWindow(w)}`}
                          className="absolute top-2 bottom-2 flex items-center overflow-hidden rounded bg-accent/70 px-2 text-[11px] font-medium whitespace-nowrap text-white"
                          key={`${w.startsAt}-${w.endsAt}`}
                          role="img"
                          style={{ left: `${pct(from)}%`, width: `${Math.max(0.5, pct(to) - pct(from))}%` }}
                          title={describeWindow(w)}
                        >
                          {shortMoment(from)} → {shortMoment(to)}
                        </span>
                      );
                    })
                  )}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
}

/** Mobile: one card per half-day, with who is missing. */
function HalfDayCards({ segments, timeline }: { segments: Segment[]; timeline: PresenceTimeline }) {
  const total = timeline.registrants.length;
  const undeclared = timeline.registrants.filter((r) => r.windows.length === 0).length;

  return (
    <ul className="grid gap-3">
      {segments.map((segment) => {
        const count = presentCount(timeline.registrants, segment);
        const absent = absentDuring(timeline.registrants, segment);
        return (
          <li className="grid gap-2 rounded-lg border border-border bg-surface p-3" key={segment.startsAt}>
            <div className="flex items-center justify-between gap-3">
              <span className="text-sm font-semibold capitalize text-foreground">{segment.label}</span>
              <span className="text-sm text-foreground">
                <span className="font-semibold">{count}</span> / {total}
              </span>
            </div>
            <span className="h-1.5 overflow-hidden rounded-full bg-surface-2">
              <span className={`block h-full ${tone(count, total)}`} style={{ width: `${(count / Math.max(1, total)) * 100}%` }} />
            </span>
            {absent.length > 0 ? (
              <p className="text-xs text-muted-foreground">Absents : {absent.map((r) => r.displayName).join(", ")}</p>
            ) : (
              <p className="text-xs text-muted-foreground">Aucun absent déclaré.</p>
            )}
            {undeclared > 0 ? <p className="text-xs text-muted-foreground">{undeclared} sans créneau, comptés présents.</p> : null}
          </li>
        );
      })}
    </ul>
  );
}
