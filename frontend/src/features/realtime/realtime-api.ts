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

// Discriminants of every frame published on the `/sessions/{id}` Mercure topic
// (SessionLifecycleManager publishes the full Session payload; id + status are the
// stable core). Shared by the admin session page and the connection gate - one
// structural check for the session family (story 33.19 review).
export type SessionStatusFrame = {
  id: string;
  status: string;
};

export function isSessionStatusFrame(v: unknown): v is SessionStatusFrame {
  if (typeof v !== "object" || v === null) return false;
  if (!("id" in v) || typeof v.id !== "string") return false;
  return "status" in v && typeof v.status === "string";
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

/** Signature of the per-page `connect(...)` that opens the EventSource for a topic. */
export type ConnectWithToken = (token: string, hubUrl: string, topic: string) => void;

/**
 * Re-mints a subscribe token, then reconnects with it.
 *
 * The subscribe JWT lives one hour (see the `*-token` endpoints) and EventSource gives up for good
 * on a 401 rather than retrying, so reconnecting with the token of the *first* connection turns
 * every drop past that hour into an unrecoverable loop: the page keeps reopening a stream the hub
 * has already rejected, and only a reload recovers. apiFetch also refreshes an expired access token
 * on the way.
 *
 * Falls back to the current token when re-minting fails, so a transient API blip still gets its
 * reconnection attempt instead of killing the stream outright.
 */
export function reconnectWithFreshToken(
  tokenPath: string,
  current: SubscribeTokenPayload,
  connect: ConnectWithToken,
  isCancelled: () => boolean = () => false,
): void {
  void (async () => {
    const payload = await fetchSubscribeToken(tokenPath);
    if (isCancelled()) return;
    const next = payload ?? current;
    connect(next.token, next.hubUrl, next.topic);
  })();
}
