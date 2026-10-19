import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import type { PublicEvent } from "./event-types";
import { eventDate, eventStatusAt } from "./event-schedule";

/**
 * Story 34.10: cache tags of the public event reads. The API asks the frontend to revalidate them when an
 * event is written (`POST /api/revalidate`), so a published event shows at once instead of after five minutes.
 */
export const EVENTS_TAG = "events";
export function eventTag(eventId: string): string {
  return `event:${eventId}`;
}

type PublicEventPayload = {
  id: string;
  title: string;
  description: string;
  coverImageUrl: string | null;
  photoGallery: string[];
  status: "published" | "in-progress" | "completed";
  startsAt: string;
  endsAt: string;
  venue: string;
  capacity: number;
  confirmedRegistrations: number;
  registrationOpensAt: string;
  registrationClosesAt: string;
  isPublic: boolean;
  hasPrivateAccessPassword: boolean;
  vodUrl: string | null;
  recapPostSlug: string | null;
  hasRecap: boolean;
  checkoutEmbedUrl: string | null;
  checkoutUnavailable: boolean;
};

export async function getPublicEvents(): Promise<{ upcoming: PublicEvent[]; past: PublicEvent[] }> {
  try {
    // ISR: cache for 5 min so listing/home pages can be statically served (story 34.4). The tag lets the API
    // drop it as soon as an event is written (story 34.10).
    const response = await apiFetch(`${env.apiBaseUrl}/events`, {
      next: { revalidate: 300, tags: [EVENTS_TAG] },
    });

    if (!response.ok) {
      return fallbackEvents();
    }

    const payload: unknown = await response.json();
    if (!isPublicEventListPayload(payload)) {
      return fallbackEvents();
    }

    const events = payload.data.map(toPublicEvent);

    return {
      upcoming: events.filter((event) => event.status !== "completed"),
      past: events.filter((event) => event.status === "completed"),
    };
  } catch {
    return fallbackEvents();
  }
}

export async function getPublicEvent(eventId: string): Promise<PublicEvent | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/events/${eventId}`, {
      next: { revalidate: 300, tags: [EVENTS_TAG, eventTag(eventId)] },
    });

    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();
    if (!isPublicEventPayload(payload)) {
      return null;
    }

    return toPublicEvent(payload.data);
  } catch {
    return null;
  }
}

function toPublicEvent(event: PublicEventPayload): PublicEvent {
  return {
    id: event.id,
    title: event.title,
    date: eventDate(event.startsAt),
    dateIso: event.startsAt,
    endDateIso: event.endsAt,
    registrationOpensAtIso: event.registrationOpensAt,
    registrationClosesAtIso: event.registrationClosesAt,
    location: event.venue,
    description: event.description,
    coverImageUrl: event.coverImageUrl ?? null,
    photoGallery: Array.isArray(event.photoGallery) ? event.photoGallery.slice(0, 12) : [],
    status: eventStatusAt(event, new Date()),
    membersOnly: !event.isPublic,
    capacity: {
      remaining: Math.max(event.capacity - event.confirmedRegistrations, 0),
      total: event.capacity,
    },
    recapAvailable: event.hasRecap,
    checkoutUnavailable: event.checkoutUnavailable,
    ...(event.vodUrl ? { vodUrl: event.vodUrl } : {}),
    ...(event.recapPostSlug ? { recap: event.recapPostSlug } : {}),
    ...(event.checkoutEmbedUrl ? { checkoutEmbedUrl: event.checkoutEmbedUrl } : {}),
  };
}

function fallbackEvents(): { upcoming: PublicEvent[]; past: PublicEvent[] } {
  return { upcoming: [], past: [] };
}

function isPublicEventListPayload(payload: unknown): payload is { data: PublicEventPayload[] } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload)) return false;
  return Array.isArray(payload.data) && payload.data.every(isPublicEventPayloadItem);
}

function isPublicEventPayload(payload: unknown): payload is { data: PublicEventPayload } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload)) return false;
  const data = payload.data;
  if (typeof data !== "object" || data === null) return false;
  return isPublicEventPayloadItem(data);
}

function isPublishedStatus(v: unknown): v is PublicEventPayload["status"] {
  return v === "published" || v === "in-progress" || v === "completed";
}

function isStringArray(v: unknown): v is string[] {
  return Array.isArray(v) && v.every((item): item is string => typeof item === "string");
}

function isPublicEventPayloadItem(v: unknown): v is PublicEventPayload {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id")) return false;
  if (!hasStringProp(v, "title")) return false;
  if (!hasStringProp(v, "description")) return false;
  if (!("coverImageUrl" in v) || (v.coverImageUrl !== null && typeof v.coverImageUrl !== "string")) return false;
  if (!("photoGallery" in v) || !isStringArray(v.photoGallery)) return false;
  if (!("status" in v) || !isPublishedStatus(v.status)) return false;
  if (!hasStringProp(v, "startsAt")) return false;
  if (!hasStringProp(v, "endsAt")) return false;
  if (!hasStringProp(v, "venue")) return false;
  if (!hasNumberProp(v, "capacity")) return false;
  if (!hasNumberProp(v, "confirmedRegistrations")) return false;
  if (!hasStringProp(v, "registrationOpensAt")) return false;
  if (!hasStringProp(v, "registrationClosesAt")) return false;
  if (!hasBooleanProp(v, "isPublic")) return false;
  if (!hasBooleanProp(v, "hasPrivateAccessPassword")) return false;
  if (!("vodUrl" in v) || (v.vodUrl !== null && typeof v.vodUrl !== "string")) return false;
  if (!("recapPostSlug" in v) || (v.recapPostSlug !== null && typeof v.recapPostSlug !== "string")) return false;
  if (!hasBooleanProp(v, "hasRecap")) return false;
  if (!("checkoutEmbedUrl" in v) || (v.checkoutEmbedUrl !== null && typeof v.checkoutEmbedUrl !== "string")) return false;
  return hasBooleanProp(v, "checkoutUnavailable");
}
