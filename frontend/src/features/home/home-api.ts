import { env } from "@/lib/env";
import { isCommunityStatsPayload, type CommunityStats } from "@/features/community/community-api";
import type { PublicEvent } from "@/features/events/event-types";
import { isEventRecapIndexPayload, type EventRecapIndexEntry } from "@/features/recap/recap-api";
import { isCurrentRunsPayload, type CurrentWeeklyRun } from "@/features/weekly-runs/weekly-runs-api";

/**
 * Story 34.9: what the home page reads, on the server, kept five minutes like the page itself (ISR). Each read
 * fails soft: an empty list or null folds its section away.
 */

const REVALIDATE = { next: { revalidate: 300 } } as const;

async function getJson(path: string): Promise<unknown> {
  try {
    const res = await fetch(`${env.apiBaseUrl}${path}`, REVALIDATE);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return payload;
  } catch {
    return null;
  }
}

/** This week's weekly runs (anonymous: no `myEntry`). */
export async function getHomeWeeklyRuns(): Promise<CurrentWeeklyRun[]> {
  const payload = await getJson("/weekly-runs/current");
  return isCurrentRunsPayload(payload) ? payload.data.filter((run) => run.status === "active") : [];
}

export async function getHomeCommunityStats(): Promise<CommunityStats | null> {
  const payload = await getJson("/community/stats");
  return isCommunityStatsPayload(payload) ? payload.data : null;
}

/** Past events, the most recent first (the public list does not promise an order). */
export function newestFirst(events: PublicEvent[]): PublicEvent[] {
  return [...events].sort((a, b) => (b.dateIso ?? "").localeCompare(a.dateIso ?? ""));
}

/** A finished session of a past event, with the event it belongs to. */
export type HomeRecap = EventRecapIndexEntry & { eventTitle: string };

/** The latest public recaps, from the most recent past events. */
export async function getHomeRecaps(past: PublicEvent[], limit = 2): Promise<HomeRecap[]> {
  const recent = past.slice(0, 3);
  const indexes = await Promise.all(
    recent.map(async (event) => {
      const payload = await getJson(`/events/${encodeURIComponent(event.id)}/parties`);
      return isEventRecapIndexPayload(payload) ? payload.data.map((entry) => ({ ...entry, eventTitle: event.title })) : [];
    }),
  );
  return indexes
    .flat()
    .filter((entry) => entry.finishedAt !== null)
    .sort((a, b) => (b.finishedAt ?? "").localeCompare(a.finishedAt ?? ""))
    .slice(0, limit);
}
