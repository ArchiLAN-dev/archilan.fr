/**
 * Story 54.7: a slot in its context, as the tracker's address carries it (`?slot=`). It names the slot, never its
 * secrets: the tracker page reads the connection again with the player's session, through the endpoints that already
 * show it to its owner.
 *
 *   event:{registrationId}:{slot name}   a slot of an event party, from the registration that shows it
 *   run:{runId}:{slot name}              a slot of a private party
 *   weekly:{weeklyRunId}                 the one slot of a weekly run
 *
 * A slot name is unique in its multiworld (Archipelago refuses two of the same), so it identifies the slot; it is
 * URI-encoded, it may hold a colon.
 */
export type SlotRef =
  | { kind: "event"; registrationId: string; slotName: string }
  | { kind: "run"; runId: string; slotName: string }
  | { kind: "weekly"; weeklyRunId: string };

const ID = /^[A-Za-z0-9-]+$/;

export function formatSlotRef(ref: SlotRef): string {
  switch (ref.kind) {
    case "event":
      return `event:${ref.registrationId}:${encodeURIComponent(ref.slotName)}`;
    case "run":
      return `run:${ref.runId}:${encodeURIComponent(ref.slotName)}`;
    case "weekly":
      return `weekly:${ref.weeklyRunId}`;
  }
}

export function parseSlotRef(value: string | null): SlotRef | null {
  if (value === null) return null;
  const [kind, id, ...rest] = value.split(":");
  if (id === undefined || !ID.test(id)) return null;
  if (kind === "weekly") return rest.length === 0 ? { kind, weeklyRunId: id } : null;
  if (kind !== "event" && kind !== "run") return null;
  let slotName: string;
  try {
    slotName = decodeURIComponent(rest.join(":"));
  } catch {
    return null;
  }
  if (slotName === "") return null;
  return kind === "event" ? { kind, registrationId: id, slotName } : { kind, runId: id, slotName };
}

/** The tracker of a game, opened on one slot. */
export function trackerSlotHref(gameSlug: string, ref: SlotRef): string {
  return `/jeux/${encodeURIComponent(gameSlug)}/tracker?slot=${encodeURIComponent(formatSlotRef(ref))}`;
}

/** A slot's tracker, when its game is known (an imported seed's slot has no catalogue game, hence no tracker). */
export function slotTracker(
  gameId: string | null | undefined,
  gameSlug: string | null | undefined,
  ref: SlotRef,
): { gameId: string; gameSlug: string; ref: SlotRef } | undefined {
  if (!gameId || !gameSlug) return undefined;
  return { gameId, gameSlug, ref };
}
