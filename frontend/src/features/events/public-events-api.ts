import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import type { EventStatus, PublicEvent } from "./event-types";
import { pastEvents, upcomingEvents } from "./mock-events";

type PublicEventPayload = {
  id: string;
  title: string;
  description: string;
  coverImageUrl: string | null;
  photoGallery: string[];
  type: string;
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
  const fallback = [...upcomingEvents, ...pastEvents].find((event) => event.id === eventId) ?? null;

  try {
    const response = await apiFetch(`${env.apiBaseUrl}/events/${eventId}`, {
      cache: "no-store",
    });

    if (!response.ok) {
      return fallback;
    }

    const payload: unknown = await response.json();
    if (!isPublicEventPayload(payload)) {
      return fallback;
    }

    return toPublicEvent(payload.data);
  } catch {
    return fallback;
  }
}

function toPublicEvent(event: PublicEventPayload): PublicEvent {
  return {
    id: event.id,
    title: event.title,
    type: event.type,
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

function fallbackEvents() {
  return {
    upcoming: upcomingEvents,
    past: pastEvents,
  };
}

function isPublicEventListPayload(payload: unknown): payload is { data: PublicEventPayload[] } {
  return Boolean(payload && typeof payload === "object" && "data" in payload && Array.isArray((payload as { data: unknown }).data));
}

function isPublicEventPayload(payload: unknown): payload is { data: PublicEventPayload } {
  const data = payload && typeof payload === "object" && "data" in payload ? (payload as { data: unknown }).data : null;

  return Boolean(data && typeof data === "object" && "id" in data && "title" in data);
}
