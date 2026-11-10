import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp } from "@/lib/type-guards";

/** Story 11.10: someone who plays a slot of an event they did not declare. */
export type EventCoPlayer = { userId: string; displayName: string };

export type EventSlotCoPlayersView = {
  coPlayers: EventCoPlayer[];
  /** The event's confirmed registrants who may be added (empty for whoever cannot manage). */
  candidates: EventCoPlayer[];
  canManage: boolean;
};

function isEventCoPlayer(v: unknown): v is EventCoPlayer {
  return typeof v === "object" && v !== null && hasStringProp(v, "userId") && hasStringProp(v, "displayName");
}

function asCoPlayers(v: unknown): EventCoPlayer[] | null {
  return Array.isArray(v) && v.every(isEventCoPlayer) ? v : null;
}

export function parseEventSlotCoPlayersView(payload: unknown): EventSlotCoPlayersView | null {
  if (typeof payload !== "object" || payload === null) return null;
  const data: unknown = Reflect.get(payload, "data");
  if (typeof data !== "object" || data === null) return null;
  const coPlayers = asCoPlayers(Reflect.get(data, "coPlayers"));
  const candidates = asCoPlayers(Reflect.get(data, "candidates"));
  const canManage: unknown = Reflect.get(data, "canManage");
  if (coPlayers === null || candidates === null || typeof canManage !== "boolean") return null;
  return { coPlayers, candidates, canManage };
}

export const eventSlotCoPlayersKey = (eventId: string, slotId: string) => ["event-slot-co-players", eventId, slotId] as const;

/** The roster of a slot, or null when the caller may not see it (or on any failure). */
export async function fetchEventSlotCoPlayers(eventId: string, slotId: string): Promise<EventSlotCoPlayersView | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${eventId}/slots/${slotId}/co-players`);
    if (!res.ok) return null;
    return parseEventSlotCoPlayersView(await res.json());
  } catch {
    return null;
  }
}

/** Replace the whole roster of a slot; an admin or the slot's holder only. */
export async function replaceEventSlotCoPlayers(eventId: string, slotId: string, userIds: string[]): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${eventId}/slots/${slotId}/co-players`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ userIds }),
    });
    return res.ok;
  } catch {
    return false;
  }
}
