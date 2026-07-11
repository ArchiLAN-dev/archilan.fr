import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// Payload returned by every `*-token` Mercure subscribe endpoint (players-token, feed-token,
// reachable-token, hints-token...): one short-lived JWT scoped to one topic. Story 33.19 unified
// the 12 identical per-page fetch+cast copies onto this single guarded fetch.
export type SubscribeTokenPayload = {
  token: string;
  hubUrl: string;
  topic: string;
};

export function isSubscribeTokenPayload(v: unknown): v is SubscribeTokenPayload {
  if (typeof v !== "object" || v === null) return false;
  if (!("token" in v) || !("hubUrl" in v) || !("topic" in v)) return false;
  return typeof v.token === "string" && typeof v.hubUrl === "string" && typeof v.topic === "string";
}

/**
 * Fetches a Mercure subscribe token from an api `*-token` endpoint (path relative to the api base,
 * e.g. `/sessions/{id}/players-token`). Cookie-authenticated via apiFetch (401-refresh preserved).
 * Returns null on any error or invalid shape - callers render their "unavailable" state.
 */
export async function fetchSubscribeToken(path: string): Promise<SubscribeTokenPayload | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null) return null;
    const data: unknown = "data" in payload ? payload.data : null;
    return isSubscribeTokenPayload(data) ? data : null;
  } catch {
    return null;
  }
}
