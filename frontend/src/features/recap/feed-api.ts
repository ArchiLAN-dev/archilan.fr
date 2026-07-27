import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableNumberProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

/** A persisted item event (story 32.6): who found what, for whom, and when. */
export type FeedEvent = {
  id: string;
  type: string;
  text: string;
  occurredAt: string;
  item: { id: number | null; name: string | null };
  location: { id: number | null; name: string | null };
  sender: { slot: number | null; name: string | null; game: string | null };
  receiver: { slot: number | null; name: string | null; game: string | null };
};

function isParty(v: unknown): v is FeedEvent["sender"] {
  return (
    typeof v === "object" &&
    v !== null &&
    hasNullableNumberProp(v, "slot") &&
    hasNullableStringProp(v, "name") &&
    hasNullableStringProp(v, "game")
  );
}

function isItemOrLocation(v: unknown): boolean {
  return typeof v === "object" && v !== null && hasNullableNumberProp(v, "id") && hasNullableStringProp(v, "name");
}

export function isFeedEvent(v: unknown): v is FeedEvent {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "type") || !hasStringProp(v, "text") || !hasStringProp(v, "occurredAt")) {
    return false;
  }
  return (
    "item" in v && isItemOrLocation(v.item) &&
    "location" in v && isItemOrLocation(v.location) &&
    "sender" in v && isParty(v.sender) &&
    "receiver" in v && isParty(v.receiver)
  );
}

export function parseFeedPayload(payload: unknown): FeedEvent[] {
  if (typeof payload !== "object" || payload === null || !("data" in payload) || !Array.isArray(payload.data)) {
    return [];
  }
  return payload.data.every(isFeedEvent) ? payload.data : [];
}

/**
 * Client-side fetch of the session's persisted feed (oldest first). For the live timeline on the run
 * page - the recap page fetches server-side via `feed-api.server`. Returns [] on any failure.
 */
export async function fetchSessionFeed(sessionId: string): Promise<FeedEvent[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/parties/${sessionId}/feed`);
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    return parseFeedPayload(payload);
  } catch {
    return [];
  }
}
