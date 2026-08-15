import { cache } from "react";
import { cookies } from "next/headers";
import { env } from "@/lib/env";
import {
  isEventRecapIndexPayload,
  parseRecapPayload,
  type EventRecapIndexEntry,
  type SessionRecap,
} from "./recap-api";

// Returns [] on any error or invalid shape: the event page must never break because of the index.
export const getEventRecapIndex = cache(async (eventId: string): Promise<EventRecapIndexEntry[]> => {
  try {
    const response = await fetch(`${env.apiBaseUrl}/events/${encodeURIComponent(eventId)}/parties`, {
      cache: "no-store",
    });

    if (!response.ok) {
      return [];
    }

    const payload: unknown = await response.json();
    if (!isEventRecapIndexPayload(payload)) {
      return [];
    }

    return payload.data;
  } catch {
    return [];
  }
});

/**
 * Server-side (SSR) fetch of a session recap. Forwards the viewer's cookies, which serves event and
 * published personal-run recaps to everyone. It does NOT authenticate the owner/participants of a
 * *private* run: their `__Host-archilan_session` cookie is host-bound to the API subdomain and never
 * reaches this frontend-host request. That case is handled client-side (story 32.5) - see
 * {@link fetchSessionRecap} and the recap page's fallback. Returns null on any error/unauthorized.
 */
export const getSessionRecap = cache(async (sessionId: string): Promise<SessionRecap | null> => {
  try {
    const cookieHeader = (await cookies()).toString();
    const response = await fetch(`${env.apiBaseUrl}/parties/${sessionId}/recap`, {
      cache: "no-store",
      headers: cookieHeader ? { cookie: cookieHeader } : undefined,
    });

    if (!response.ok) {
      return null;
    }

    return parseRecapPayload(await response.json());
  } catch {
    return null;
  }
});
