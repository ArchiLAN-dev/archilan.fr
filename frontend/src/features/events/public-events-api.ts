import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import type { EventStatus, PublicEvent } from "./event-types";

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
    const response = await apiFetch(`${env.apiBaseUrl}/events`, {
      cache: "no-store",
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
      cache: "no-store",
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
    date: formatDate(event.startsAt),
    dateIso: event.startsAt,
    endDateIso: event.endsAt,
    location: event.venue,
    description: event.description,
    coverImageUrl: event.coverImageUrl ?? null,
    photoGallery: Array.isArray(event.photoGallery) ? event.photoGallery.slice(0, 12) : [],
    status: toCardStatus(event),
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

function toCardStatus(event: PublicEventPayload): EventStatus {
  if (!event.isPublic) {
    return "members-only";
  }

  if (event.status === "completed") {
    return "completed";
  }

  if (event.status === "in-progress") {
    return "open";
  }

  return new Date(event.registrationOpensAt ?? event.startsAt) > new Date() ? "upcoming" : "open";
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    dateStyle: "long",
  }).format(new Date(value));
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
