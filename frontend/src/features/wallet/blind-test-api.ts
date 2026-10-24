import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/**
 * Story 41.40: the blind test of an event. Its app credits event pelles for the sources found; the site hands
 * registrants a signed link into it and lets the admins tune it. Every function returns a value instead of throwing
 * (AC-API2).
 */
export type BlindTestSettings = { enabled: boolean; pellesPerSource: number; capPerPlayer: number };

export type SaveBlindTestResult = { kind: "ok"; settings: BlindTestSettings } | { kind: "error"; message: string };

export function isBlindTestSettings(v: unknown): v is BlindTestSettings {
  return (
    typeof v === "object" &&
    v !== null &&
    hasBooleanProp(v, "enabled") &&
    hasNumberProp(v, "pellesPerSource") &&
    hasNumberProp(v, "capPerPlayer")
  );
}

function dataOf(payload: unknown): unknown {
  return typeof payload === "object" && payload !== null && "data" in payload ? payload.data : null;
}

/** The signed link into the app, or null when the member may not play (signed out, not registered, closed). */
export async function fetchBlindTestJoinUrl(eventId: string): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${eventId}/blind-test/join`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return typeof data === "object" && data !== null && hasStringProp(data, "url") ? data.url : null;
  } catch {
    return null;
  }
}

export async function fetchBlindTestSettings(eventId: string): Promise<BlindTestSettings | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/blind-test`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isBlindTestSettings(data) ? data : null;
  } catch {
    return null;
  }
}

/** The server owns the bounds; this relays its message. */
export async function saveBlindTestSettings(eventId: string, settings: BlindTestSettings): Promise<SaveBlindTestResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/blind-test`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(settings),
    });
    const payload: unknown = await res.json();
    const data = dataOf(payload);
    if (res.ok && isBlindTestSettings(data)) return { kind: "ok", settings: data };
    const error = typeof payload === "object" && payload !== null && "error" in payload ? payload.error : null;
    const message = typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : null;
    return { kind: "error", message: message ?? "Réglages non enregistrés. Réessaie." };
  } catch {
    return { kind: "error", message: "Réglages non enregistrés. Réessaie." };
  }
}
