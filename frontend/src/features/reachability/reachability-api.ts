import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

/**
 * Archipelago slot name to the pseudo(s) of the member(s) the slot belongs to.
 *
 * Nothing the bridge publishes carries a pseudo: it names a slot by the name the generator gave it,
 * which is the player's yaml `name:` when they set one. The goal celebration needs the human behind
 * the slot, and used to fall back on the viewer's own pseudo.
 *
 * A slot can be played by several people (story 16.17), so the API sends a list and this joins it:
 * naming one of three players would be as wrong as naming the viewer.
 */
export type SlotOwners = Record<string, string>;

function isSlotOwnersPayload(v: unknown): v is { slots: { slotName: string; playerNames: string[] }[] } {
  if (typeof v !== "object" || v === null || !("slots" in v) || !Array.isArray(v.slots)) return false;
  return v.slots.every(
    (slot: unknown) =>
      typeof slot === "object" &&
      slot !== null &&
      "slotName" in slot &&
      typeof slot.slotName === "string" &&
      "playerNames" in slot &&
      Array.isArray(slot.playerNames) &&
      slot.playerNames.every((name: unknown) => typeof name === "string"),
  );
}

/** "A", "A et B", "A, B et C" - the shape a French reader expects, however many players there are. */
function joinPlayerNames(names: string[]): string {
  if (names.length <= 1) return names[0] ?? "";
  return `${names.slice(0, -1).join(", ")} et ${names[names.length - 1]}`;
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
      const label = joinPlayerNames(slot.playerNames);
      if (label !== "") owners[slot.slotName] = label;
    }

    return owners;
  } catch {
    return {};
  }
}
