import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/**
 * Stories 41.40, 41.45: the blind test of an event. Its app credits event pelles for the sources found; the site
 * shows registrants the way in, tells the app who they are through a one-time code, and lets the admins tune it. Every function returns a value instead of throwing
 * (AC-API2).
 */
export type BlindTestSettingsInput = { enabled: boolean; pellesPerSource: number; capPerPlayer: number };

/** The settings as the server gives them back, with the app's console for the event (story 41.46; null when not configured). */
export type BlindTestSettings = BlindTestSettingsInput & { consoleUrl: string | null };

export type SaveBlindTestResult = { kind: "ok"; settings: BlindTestSettings } | { kind: "error"; message: string };

export function isBlindTestSettings(v: unknown): v is BlindTestSettings {
  return (
    typeof v === "object" &&
    v !== null &&
    hasBooleanProp(v, "enabled") &&
    hasNumberProp(v, "pellesPerSource") &&
    hasNumberProp(v, "capPerPlayer") &&
    hasNullableStringProp(v, "consoleUrl")
  );
}

function dataOf(payload: unknown): unknown {
  return typeof payload === "object" && payload !== null && "data" in payload ? payload.data : null;
}

/** Where the member enters the app (story 41.45), or null when they may not play now (signed out, not registered, closed). */
export async function fetchBlindTestEntryUrl(eventId: string): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${eventId}/blind-test/access`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return typeof data === "object" && data !== null && hasStringProp(data, "url") ? data.url : null;
  } catch {
    return null;
  }
}

export type BlindTestLoginResult = { kind: "ok"; redirect: string } | { kind: "error"; message: string };

/**
 * The site's half of the login (story 41.45): a one-time code for the app, which the browser carries back to it. The
 * return address comes from the server; the state is the app's own.
 */
export async function requestBlindTestLogin(eventId: string, state: string): Promise<BlindTestLoginResult> {
  return requestCode(`${env.apiBaseUrl}/events/${encodeURIComponent(eventId)}/blind-test/codes`, state, "Connexion au blind test impossible. Relance-la depuis l'app.");
}

/** Story 41.53: an admin's one-time code for the app's console, régie and room screen. */
export async function requestBlindTestAnimation(state: string): Promise<BlindTestLoginResult> {
  return requestCode(`${env.apiBaseUrl}/blind-test/animation/codes`, state, "Connexion à l'animation impossible. Relance-la depuis l'app.");
}

async function requestCode(url: string, state: string, failure: string): Promise<BlindTestLoginResult> {
  try {
    const res = await apiFetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ state }),
    });
    const payload: unknown = await res.json();
    const data = dataOf(payload);
    if (res.ok && typeof data === "object" && data !== null && hasStringProp(data, "redirect")) {
      return { kind: "ok", redirect: data.redirect };
    }
    const error = typeof payload === "object" && payload !== null && "error" in payload ? payload.error : null;
    const message = typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : null;
    return { kind: "error", message: message ?? failure };
  } catch {
    return { kind: "error", message: failure };
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
export async function saveBlindTestSettings(eventId: string, settings: BlindTestSettingsInput): Promise<SaveBlindTestResult> {
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
