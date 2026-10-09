import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";
import type { FriendCard } from "@/features/community/community-friends-api";

/** Story 43.10: a player of another slot, and whether they are the viewer's friend. */
export type ExchangePlayer = FriendCard & { isFriend: boolean };

/** What the viewer's slots exchanged with one other slot of the session. */
export type SlotExchange = {
  slotName: string;
  players: ExchangePlayer[];
  hasFriend: boolean;
  sent: number;
  received: number;
  sentProgression: number;
  receivedProgression: number;
};

/** A BK of the viewer's ended by an item another slot sent. */
export type Unblock = { slotName: string; itemName: string | null; senderName: string; at: string; senders: ExchangePlayer[] };

export type RecapExchanges = { exchanges: SlotExchange[]; unblocks: Unblock[] };

function isPlayer(v: unknown): v is ExchangePlayer {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "userId") &&
    hasStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl") &&
    "isFriend" in v &&
    typeof v.isFriend === "boolean"
  );
}

function isSlotExchange(v: unknown): v is SlotExchange {
  if (typeof v !== "object" || v === null || !hasStringProp(v, "slotName")) return false;
  if (!("players" in v) || !Array.isArray(v.players) || !v.players.every(isPlayer)) return false;
  if (!("hasFriend" in v) || typeof v.hasFriend !== "boolean") return false;
  if (!("sent" in v) || typeof v.sent !== "number" || !("received" in v) || typeof v.received !== "number") return false;
  return (
    "sentProgression" in v &&
    typeof v.sentProgression === "number" &&
    "receivedProgression" in v &&
    typeof v.receivedProgression === "number"
  );
}

function isUnblock(v: unknown): v is Unblock {
  if (typeof v !== "object" || v === null || !hasStringProp(v, "slotName") || !hasStringProp(v, "senderName")) return false;
  if (!hasStringProp(v, "at") || !hasNullableStringProp(v, "itemName")) return false;
  return "senders" in v && Array.isArray(v.senders) && v.senders.every(isPlayer);
}

/** « Entre nous » (story 43.10): null for someone who did not play the session, and on failure. */
export async function fetchRecapExchanges(sessionId: string): Promise<RecapExchanges | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/parties/${encodeURIComponent(sessionId)}/recap/exchanges`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    const data = typeof json === "object" && json !== null && "data" in json ? json.data : null;
    if (typeof data !== "object" || data === null) return null;
    if (!("exchanges" in data) || !Array.isArray(data.exchanges) || !data.exchanges.every(isSlotExchange)) return null;
    if (!("unblocks" in data) || !Array.isArray(data.unblocks) || !data.unblocks.every(isUnblock)) return null;
    return { exchanges: data.exchanges, unblocks: data.unblocks };
  } catch {
    return null;
  }
}
