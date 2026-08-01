import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableNumberProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

/**
 * A persisted item event (story 32.6): who found what, for whom, and when. `item.flags` are the AP
 * classification bits (bit 1 = progression, story 32.9); null on rows persisted before the flag
 * existed.
 */
export type FeedEvent = {
  id: string;
  type: string;
  text: string;
  occurredAt: string;
  item: { id: number | null; name: string | null; flags: number | null };
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

function isItemRef(v: unknown): v is FeedEvent["item"] {
  return (
    typeof v === "object" &&
    v !== null &&
    hasNullableNumberProp(v, "id") &&
    hasNullableStringProp(v, "name") &&
    hasNullableNumberProp(v, "flags")
  );
}

function isLocationRef(v: unknown): boolean {
  return typeof v === "object" && v !== null && hasNullableNumberProp(v, "id") && hasNullableStringProp(v, "name");
}

export function isFeedEvent(v: unknown): v is FeedEvent {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "type") || !hasStringProp(v, "text") || !hasStringProp(v, "occurredAt")) {
    return false;
  }
  return (
    "item" in v && isItemRef(v.item) &&
    "location" in v && isLocationRef(v.location) &&
    "sender" in v && isParty(v.sender) &&
    "receiver" in v && isParty(v.receiver)
  );
}

/** Whether the found item is a progression item (AP flags bit 1) - vs useful/trap/filler. */
export function isProgressionFind(event: FeedEvent): boolean {
  return ((event.item.flags ?? 0) & 1) === 1;
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
