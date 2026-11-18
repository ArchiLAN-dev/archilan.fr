/**
 * Story 53.9: who may act on an event slot's page. Any slot of the game opens; another player's opens read-only.
 * Until the slot is known to be the viewer's own (or co-played), nothing that writes is shown.
 */
export function isReadOnlySlot(
  sourceKind: "run" | "event",
  isAdmin: boolean,
  ownSlotNames: readonly string[] | undefined,
  slotName: string | null,
): boolean {
  if (sourceKind !== "event" || isAdmin) return false;
  return !(ownSlotNames !== undefined && slotName !== null && ownSlotNames.includes(slotName));
}

export type SwitcherSlot = { index: string; name: string; own: boolean };

/** Story 53.9: every slot of the game for the switcher, the viewer's own first, then by Archipelago slot number. */
export function switcherSlots(
  slots: Record<string, { slot_name: string }>,
  ownSlotNames: readonly string[] | undefined,
): SwitcherSlot[] {
  const own = new Set(ownSlotNames ?? []);
  return Object.entries(slots)
    .filter(([, slot]) => slot.slot_name !== "Bridge")
    .map(([index, slot]) => ({ index, name: slot.slot_name, own: own.has(slot.slot_name) }))
    .sort((a, b) => Number(b.own) - Number(a.own) || Number(a.index) - Number(b.index));
}
