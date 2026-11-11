"use client";

import { useQuery } from "@tanstack/react-query";

import { fetchAccountRegistrations, type Registration } from "@/features/auth/auth-api";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { registrantHubPath } from "./registrant-hub-api";
import { useMinuteClock } from "./use-minute-clock";

export type HubEntry = {
  href: string;
  eventTitle: string;
};

/** Story 53.7: the menu entry shows from 14 days before the event. */
export const HUB_ENTRY_WINDOW_DAYS = 14;

const DAY_MS = 86_400_000;

/** Event statuses with no space to open: not public yet, or closed by the orga. */
const CLOSED_EVENT_STATUSES = ["draft", "completed"];

/**
 * Story 53.7: the registrant's space the account menu leads to: an event in progress first, else the next one starting
 * within 14 days. Cancelled registrations, past events and events without dates are left out.
 */
export function pickHubEntry(registrations: readonly Registration[], now: Date): HubEntry | null {
  const nowMs = now.getTime();
  const candidates = registrations.flatMap((registration) => {
    if (registration.registrationStatus === "cancelled") return [];
    if (registration.eventStatus !== undefined && CLOSED_EVENT_STATUSES.includes(registration.eventStatus)) return [];
    const startsAt = parseTime(registration.eventStartsAt);
    if (startsAt === null) return [];
    // An event without an end date lasts its day.
    const endsAt = parseTime(registration.eventEndsAt) ?? startsAt + DAY_MS;
    if (endsAt < nowMs) return [];
    if (startsAt - nowMs > HUB_ENTRY_WINDOW_DAYS * DAY_MS) return [];
    return [{ registration, startsAt, inProgress: startsAt <= nowMs }];
  });
  if (candidates.length === 0) return null;

  candidates.sort((a, b) => Number(b.inProgress) - Number(a.inProgress) || a.startsAt - b.startsAt);
  const { registration } = candidates[0];
  return { href: registrantHubPath(registration.eventSlug), eventTitle: registration.eventTitle };
}

function parseTime(value: string | null | undefined): number | null {
  if (typeof value !== "string" || value === "") return null;
  const time = Date.parse(value);
  return Number.isNaN(time) ? null : time;
}

/** Story 53.7: the "Mon event" entry of the signed-in member, or null outside the window. */
export function useHubEntry(enabled: boolean): HubEntry | null {
  const now = useMinuteClock();
  const { data } = useQuery({
    queryKey: ["account-registrations"],
    queryFn: fetchAccountRegistrations,
    enabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  if (!enabled || now === null || !data) return null;
  return pickHubEntry(data, now);
}
