import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/**
 * Story 49.1: an event's link to Minimapperz, admin side. The API key is written, never read back: the server only
 * says whether one is set and how it ends. Every function returns a value instead of throwing (AC-API2).
 */
export type MinimapperzSettings = {
  enabled: boolean;
  slug: string | null;
  apiKeySet: boolean;
  apiKeyEnd: string | null;
  pellesPerGame: number;
  pellesPerWin: number;
  capPerPlayer: number;
};

/** What the form sends: `apiKey` empty keeps the key on file, `clearApiKey` removes it. */
export type MinimapperzSettingsInput = {
  enabled: boolean;
  slug: string;
  apiKey: string;
  clearApiKey: boolean;
  pellesPerGame: number;
  pellesPerWin: number;
  capPerPlayer: number;
};

export type MinimapperzEvent = { slug: string; name: string; opensAt: string; closesAt: string; isOpen: boolean };

export type MinimapperzCheck =
  | { status: "ok"; event: MinimapperzEvent }
  | { status: "not_configured" | "unauthorized" | "unreachable"; event: null };

export type SaveMinimapperzResult = { kind: "ok"; settings: MinimapperzSettings } | { kind: "error"; message: string; fields: Record<string, string> };

const CHECK_STATUSES = ["not_configured", "unauthorized", "unreachable"] as const;

export function isMinimapperzSettings(v: unknown): v is MinimapperzSettings {
  return (
    typeof v === "object" &&
    v !== null &&
    hasBooleanProp(v, "enabled") &&
    hasNullableStringProp(v, "slug") &&
    hasBooleanProp(v, "apiKeySet") &&
    hasNullableStringProp(v, "apiKeyEnd") &&
    hasNumberProp(v, "pellesPerGame") &&
    hasNumberProp(v, "pellesPerWin") &&
    hasNumberProp(v, "capPerPlayer")
  );
}

function isMinimapperzEvent(v: unknown): v is MinimapperzEvent {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "slug") &&
    hasStringProp(v, "name") &&
    hasStringProp(v, "opensAt") &&
    hasStringProp(v, "closesAt") &&
    hasBooleanProp(v, "isOpen")
  );
}

export function isMinimapperzCheck(v: unknown): v is MinimapperzCheck {
  if (typeof v !== "object" || v === null || !hasStringProp(v, "status") || !("event" in v)) return false;
  if (v.status === "ok") return isMinimapperzEvent(v.event);
  return CHECK_STATUSES.some((status) => status === v.status) && v.event === null;
}

function dataOf(payload: unknown): unknown {
  return typeof payload === "object" && payload !== null && "data" in payload ? payload.data : null;
}

function settingsUrl(eventId: string): string {
  return `${env.apiBaseUrl}/admin/events/${encodeURIComponent(eventId)}/minimapperz`;
}

export async function fetchMinimapperzSettings(eventId: string): Promise<MinimapperzSettings | null> {
  try {
    const res = await apiFetch(settingsUrl(eventId));
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isMinimapperzSettings(data) ? data : null;
  } catch {
    return null;
  }
}

/** The server owns the bounds; this relays its message and the first error of each field. */
export async function saveMinimapperzSettings(eventId: string, input: MinimapperzSettingsInput): Promise<SaveMinimapperzResult> {
  const failure = "Réglages non enregistrés. Réessaie.";
  try {
    const res = await apiFetch(settingsUrl(eventId), {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(input),
    });
    const payload: unknown = await res.json();
    const data = dataOf(payload);
    if (res.ok && isMinimapperzSettings(data)) return { kind: "ok", settings: data };
    const error = typeof payload === "object" && payload !== null && "error" in payload ? payload.error : null;
    const message = typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : null;
    const details = typeof error === "object" && error !== null && "details" in error ? error.details : null;
    return { kind: "error", message: message ?? failure, fields: fieldErrors(details) };
  } catch {
    return { kind: "error", message: failure, fields: {} };
  }
}

function fieldErrors(details: unknown): Record<string, string> {
  if (typeof details !== "object" || details === null) return {};
  const fields: Record<string, string> = {};
  for (const [field, messages] of Object.entries(details)) {
    const first: unknown = Array.isArray(messages) ? messages[0] : null;
    if (typeof first === "string") fields[field] = first;
  }
  return fields;
}

/** Asks the server to open the Minimapperz event with the key on file; null when the server itself fails. */
export async function checkMinimapperzConnection(eventId: string): Promise<MinimapperzCheck | null> {
  try {
    const res = await apiFetch(`${settingsUrl(eventId)}/check`, { method: "POST" });
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isMinimapperzCheck(data) ? data : null;
  } catch {
    return null;
  }
}
