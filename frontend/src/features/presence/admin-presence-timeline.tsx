"use client";

import Link from "next/link";
import { use } from "react";
import { useQuery } from "@tanstack/react-query";

import { fetchPresenceTimeline, presenceTimelineKey, type PresenceRegistrant, type PresenceTimeline, type PresenceWindow } from "./presence-api";

export type Segment = { startsAt: number; endsAt: number; label: string };

const PARIS_HOUR = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", hourCycle: "h23", timeZone: "Europe/Paris" });
const PARIS_DAY = new Intl.DateTimeFormat("fr-FR", { weekday: "short", day: "numeric", timeZone: "Europe/Paris" });
const HOUR = 3_600_000;

/** The hour of the day in Paris, 0 to 23. */
function parisHour(t: number): number {
  return Number(PARIS_HOUR.formatToParts(new Date(t)).find((part) => part.type === "hour")?.value ?? "0");
}

/** The event cut into half-days (midnight to noon, noon to midnight, Europe/Paris), clipped to its dates. */
export function halfDaySegments(eventStartsAt: string, eventEndsAt: string): Segment[] {
  const start = new Date(eventStartsAt).getTime();
  const end = new Date(eventEndsAt).getTime();
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
    const morning = parisHour(from) < 12;
    segments.push({ startsAt: from, endsAt: to, label: `${PARIS_DAY.format(new Date(from))} ${morning ? "matin" : "après-midi"}` });
  }
  return segments;
}

/** There during the segment: no window at all (there the whole event), or a window that overlaps it. */
export function isPresentDuring(windows: PresenceWindow[], segment: Segment): boolean {
  if (windows.length === 0) return true;
  return windows.some((w) => new Date(w.startsAt).getTime() < segment.endsAt && new Date(w.endsAt).getTime() > segment.startsAt);
}

export function presentCount(registrants: PresenceRegistrant[], segment: Segment): number {
  return registrants.filter((r) => isPresentDuring(r.windows, segment)).length;
}

/** Story 52.2: who is at the event when. */
export function AdminPresenceTimelinePage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  const { data, isPending } = useQuery({
    queryKey: presenceTimelineKey(eventId),
    queryFn: () => fetchPresenceTimeline(eventId),
    staleTime: 30_000,
    retry: false,
  });

  return (
    <div className="grid gap-6 p-6 md:p-8">
      <header className="grid gap-1">
        <h1 className="font-heading text-2xl font-bold text-foreground">Qui est là quand</h1>
        <p className="text-sm text-muted-foreground">
          Les inscrits confirmés et les plages qu&apos;ils ont indiquées. Sans plage : présent tout l&apos;événement.
        </p>
      </header>
      {isPending ? (
        <div className="h-64 w-full animate-pulse rounded-xl bg-surface" />
      ) : data ? (
        <PresenceTimelineView eventId={eventId} timeline={data} />
      ) : (
        <p className="text-muted-foreground">La frise n&apos;a pas pu être chargée.</p>
      )}
    </div>
  );
}

export function PresenceTimelineView({ eventId, timeline }: { eventId: string; timeline: PresenceTimeline }) {
  const segments = halfDaySegments(timeline.eventStartsAt, timeline.eventEndsAt);
  const start = new Date(timeline.eventStartsAt).getTime();
  const span = new Date(timeline.eventEndsAt).getTime() - start;
  const at = (t: number) => `${Math.min(100, Math.max(0, ((t - start) / span) * 100))}%`;

  if (timeline.registrants.length === 0) return <p className="text-muted-foreground">Aucun inscrit confirmé pour l&apos;instant.</p>;

  return (
    <div className="grid gap-4 overflow-x-auto">
      <div className="grid min-w-[640px] grid-cols-[12rem_1fr] gap-x-3 gap-y-2 text-sm">
        <span className="text-xs text-muted-foreground">Présents</span>
        <div className="relative flex h-10">
          {segments.map((segment) => (
            <div
              className="flex flex-col items-center justify-center border-l border-border px-1 text-center"
              key={segment.startsAt}
              style={{ width: `${((segment.endsAt - segment.startsAt) / span) * 100}%` }}
            >
              <span className="text-[11px] leading-tight text-muted-foreground">{segment.label}</span>
              <span className="font-semibold text-foreground">{presentCount(timeline.registrants, segment)}</span>
            </div>
          ))}
        </div>
        {timeline.registrants.map((registrant) => (
          <div className="contents" key={registrant.registrationId}>
            <Link
              className="truncate text-foreground hover:text-accent-text hover:underline"
              href={`/admin/evenements/${eventId}/inscriptions/${registrant.registrationId}`}
            >
              {registrant.displayName}
            </Link>
            <div className="relative h-6 rounded bg-surface-2">
              {(registrant.windows.length === 0 ? [{ startsAt: timeline.eventStartsAt, endsAt: timeline.eventEndsAt }] : registrant.windows).map((w) => (
                <span
                  aria-label={registrant.windows.length === 0 ? "Présent tout l'événement" : `${w.startsAt} - ${w.endsAt}`}
                  className={`absolute inset-y-0 rounded ${registrant.windows.length === 0 ? "bg-success/40" : "bg-accent/70"}`}
                  key={`${w.startsAt}-${w.endsAt}`}
                  style={{ left: at(new Date(w.startsAt).getTime()), right: `calc(100% - ${at(new Date(w.endsAt).getTime())})` }}
                />
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
