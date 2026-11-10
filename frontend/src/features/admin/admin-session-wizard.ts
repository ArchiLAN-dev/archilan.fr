/**
 * The "new session" wizard of an event (story 11.4): one slot per registered game, a name each, and since story 11.9
 * a choice of which slots go into the session. Pure functions, the page keeps the state.
 */

export type WizardRegistration = {
  registrationId: string;
  playerName: string;
  slots: { slotId: string; gameId: string; gameName: string; archipelagoGameName: string | null }[];
};

export type WizardSlot = {
  registrationId: string;
  playerName: string;
  slotId: string;
  gameId: string;
  gameName: string;
  archipelagoGameName: string | null;
  slotName: string;
  /** Story 11.9: whether the slot goes into the session. */
  selected: boolean;
  errors: string[];
};

// ─── Slot name generation (port of SlotNameGenerator.php) ───────────────────

function abbreviateGameName(gameName: string): string {
  if (!gameName.trim()) return "UNK";
  const words = gameName.trim().split(/\s+/);
  let abbr = "";
  for (const word of words) {
    const first = Array.from(word)[0];
    if (first) abbr += first.toLocaleUpperCase();
    if (charLength(abbr) >= 3) break;
  }
  return abbr || "UNK";
}

export function charLength(value: string): number {
  return Array.from(value).length;
}

function limitChars(value: string, max: number): string {
  return Array.from(value).slice(0, max).join("");
}

function sanitizePlayerName(name: string): string {
  // Keep letters/digits/underscore (mirrors the backend SlotNameGenerator); drop the rest.
  const clean = name.replace(/[^A-Za-z0-9_]/g, "");
  return clean || "Player";
}

/** Every slot of every registration, named, all selected. */
export function generateSlotsFromRegistrations(registrations: WizardRegistration[]): WizardSlot[] {
  const pairs = registrations.flatMap((reg) =>
    reg.slots.map((slot) => {
      const abbr = abbreviateGameName(slot.archipelagoGameName ?? "");
      const player = sanitizePlayerName(reg.playerName);
      const base = limitChars(player + "_" + abbr, 16);
      return { reg, slot, base };
    }),
  );

  const counts: Record<string, number> = {};
  pairs.forEach((p) => { counts[p.base] = (counts[p.base] ?? 0) + 1; });

  const counters: Record<string, number> = {};
  return pairs.map(({ reg, slot, base }) => {
    let slotName: string;
    if (counts[base] === 1) {
      slotName = base;
    } else {
      counters[base] = (counters[base] ?? 0) + 1;
      const suffix = String(counters[base]);
      slotName = limitChars(base, 16 - charLength(suffix)) + suffix;
    }
    return {
      registrationId: reg.registrationId,
      playerName: reg.playerName,
      slotId: slot.slotId,
      gameId: slot.gameId,
      gameName: slot.gameName,
      archipelagoGameName: slot.archipelagoGameName,
      slotName,
      selected: true,
      errors: [],
    };
  });
}

/** Fresh names, the current selection kept. */
export function regenerateSlotNames(registrations: WizardRegistration[], current: WizardSlot[]): WizardSlot[] {
  const unselected = new Set(current.filter((slot) => !slot.selected).map((slot) => slot.slotId));
  return generateSlotsFromRegistrations(registrations).map((slot) => ({ ...slot, selected: !unselected.has(slot.slotId) }));
}

/** Errors by slot id, for the selected slots only: a slot left out of the session is not checked. */
export function validateSlots(slots: WizardSlot[]): Record<string, string[]> {
  const chosen = slots.filter((slot) => slot.selected);
  const names = chosen.map((s) => s.slotName);
  const errors: Record<string, string[]> = {};
  chosen.forEach((slot) => {
    const errs: string[] = [];
    if (!slot.slotName.trim()) errs.push("Nom requis");
    else if (charLength(slot.slotName) > 16) errs.push("Maximum 16 caractères");
    if (slot.slotName && names.filter((n) => n === slot.slotName).length > 1) errs.push("Nom déjà utilisé");
    if (errs.length) errors[slot.slotId] = errs;
  });
  return errors;
}

/** Whether the session can be created: at least one slot, and no error among the selected ones. */
export function canCreateSession(slots: WizardSlot[]): boolean {
  return slots.some((slot) => slot.selected) && Object.keys(validateSlots(slots)).length === 0;
}

/** The body of the creation request: the selected slots only. */
export function creationPayload(slots: WizardSlot[]): { slots: { registrationId: string; slotId: string; gameId: string; slotName: string }[] } {
  return {
    slots: slots
      .filter((slot) => slot.selected)
      .map((s) => ({ registrationId: s.registrationId, slotId: s.slotId, gameId: s.gameId, slotName: s.slotName })),
  };
}

/** The header checkbox: everything, nothing, or a mix. */
export function selectionState(slots: WizardSlot[]): "all" | "none" | "some" {
  const count = slots.filter((slot) => slot.selected).length;
  if (count === 0) return "none";
  return count === slots.length ? "all" : "some";
}

export function setSlotSelected(slots: WizardSlot[], slotId: string, selected: boolean): WizardSlot[] {
  return slots.map((slot) => (slot.slotId === slotId ? { ...slot, selected } : slot));
}

export function setAllSelected(slots: WizardSlot[], selected: boolean): WizardSlot[] {
  return slots.map((slot) => ({ ...slot, selected }));
}
