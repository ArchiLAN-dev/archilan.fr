import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

/**
 * Archipelago slot name to the pseudo of the member the slot belongs to.
 *
 * Nothing the bridge publishes carries a pseudo: it names a slot by the name the generator gave it,
 * which is the player's yaml `name:` when they set one. The goal celebration needs the human behind
 * the slot, and used to fall back on the viewer's own pseudo.
 */
export type SlotOwners = Record<string, string>;

function isSlotOwnersPayload(v: unknown): v is { slots: { slotName: string; playerName: string }[] } {
  if (typeof v !== "object" || v === null || !("slots" in v) || !Array.isArray(v.slots)) return false;
  return v.slots.every(
    (slot: unknown) =>
      typeof slot === "object" &&
      slot !== null &&
      "slotName" in slot &&
      typeof slot.slotName === "string" &&
      "playerName" in slot &&
      typeof slot.playerName === "string",
  );
}

/**
 * Owners of every slot of a session. Returns an empty map on any failure: naming a slot is a nicety,
 * never a reason to break the page that asked.
 */
export async function fetchSlotOwners(sessionId: string): Promise<SlotOwners> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/slot-owners`);
    if (!res.ok) return {};
    const payload: unknown = await res.json();
    const data: unknown =
      typeof payload === "object" && payload !== null && "data" in payload ? payload.data : payload;
    if (!isSlotOwnersPayload(data)) return {};

    const owners: SlotOwners = {};
    for (const slot of data.slots) {
      if (slot.playerName !== "") owners[slot.slotName] = slot.playerName;
    }

    return owners;
  } catch {
    return {};
  }
}
