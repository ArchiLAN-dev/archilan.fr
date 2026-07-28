import { cache } from "react";
import { cookies } from "next/headers";
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

function isFeedEvent(v: unknown): v is FeedEvent {
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

function isFeedPayload(payload: unknown): payload is { data: FeedEvent[] } {
  return (
    typeof payload === "object" &&
    payload !== null &&
    "data" in payload &&
    Array.isArray(payload.data) &&
    payload.data.every(isFeedEvent)
  );
}

/**
 * The session's persisted feed, oldest first. Returns [] on any error/empty/unauthorized so the recap
 * page never breaks over it. Forwards the viewer's cookies so a private personal-run feed loads for its
 * owner/participants (same rule as the recap, story 32.6); an event or published feed needs no auth.
 */
export const getSessionFeed = cache(async (sessionId: string): Promise<FeedEvent[]> => {
  try {
    const cookieHeader = (await cookies()).toString();
    const response = await fetch(`${env.apiBaseUrl}/parties/${sessionId}/feed`, {
      cache: "no-store",
      headers: cookieHeader ? { cookie: cookieHeader } : undefined,
    });

    if (!response.ok) {
      return [];
    }

    const payload: unknown = await response.json();
    return isFeedPayload(payload) ? payload.data : [];
  } catch {
    return [];
  }
});
