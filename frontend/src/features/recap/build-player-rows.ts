import type { SessionRecap } from "./recap-api";

export type PlayerBadge = { key: string; label: string; value: number | string };

export type PlayerRow = {
  slotId: string;
  slotName: string;
  /** Player name, suffixed with the slot name only when that name designates several slots. */
  label: string;
  game: string;
  /** Podium position, or null for a slot that is invalidated or never reached its goal. */
  rank: number | null;
  checksDone: number;
  sentToOthers: number;
  receivedFromOthers: number;
  kept: number;
  completionSeconds: number | null;
  wasReleased: boolean;
  isInvalidated: boolean;
  badges: PlayerBadge[];
};

/**
 * One row per slot for the comparative table (story 32.16).
 *
 * Aggregates come from the recap projection (`recap.graph`), never from the feed: the projection is
 * what the exchange diagram above already draws, so the two cannot disagree.
 *
 * "Sent" and "received" count exchanges **with other players**; items a slot found for itself are
 * the separate `kept` column. That is the semantics the "most generous" superlative already used,
 * and the exchange diagram was aligned onto it rather than the reverse.
 *
 * Order follows `recap.podium`, which the API already returns in podium order - re-sorting here
 * would silently compete with the server's ranking rules.
 */
/**
 * Slot id -> display label: the player name, suffixed with the slot name only when that name
 * designates several slots. Shared by every surface of the recap page, because a page that shows
 * "masterkafey" three times in three sections has told the reader nothing.
 */
export function buildSlotLabels(podium: SessionRecap["podium"]): Map<string, string> {
  const slotsPerName = new Map<string, number>();
  for (const slot of podium) {
    slotsPerName.set(slot.playerName, (slotsPerName.get(slot.playerName) ?? 0) + 1);
  }

  return new Map(
    podium.map((slot) => [
      slot.slotId,
      (slotsPerName.get(slot.playerName) ?? 0) > 1 ? `${slot.playerName} (${slot.slotName})` : slot.playerName,
    ]),
  );
}

export function buildPlayerRows(recap: SessionRecap): PlayerRow[] {
  const labels = buildSlotLabels(recap.podium);

  const sent = new Map<string, number>();
  const received = new Map<string, number>();
  for (const edge of recap.graph.edges) {
    sent.set(edge.fromSlotId, (sent.get(edge.fromSlotId) ?? 0) + edge.count);
    received.set(edge.toSlotId, (received.get(edge.toSlotId) ?? 0) + edge.count);
  }
  const kept = new Map(recap.graph.localItems.map((local) => [local.slotId, local.count]));

  const badges = new Map<string, PlayerBadge[]>();
  for (const superlative of recap.superlatives) {
    const list = badges.get(superlative.slotId) ?? [];
    list.push({ key: superlative.key, label: superlative.label, value: superlative.value });
    badges.set(superlative.slotId, list);
  }

  let rank = 0;
  return recap.podium.map((slot) => {
    const ranked = !slot.isInvalidated && slot.completionSeconds !== null;
    if (ranked) rank += 1;
    return {
      badges: badges.get(slot.slotId) ?? [],
      checksDone: slot.checksDone,
      completionSeconds: slot.completionSeconds,
      game: slot.game,
      isInvalidated: slot.isInvalidated,
      kept: kept.get(slot.slotId) ?? 0,
      label: labels.get(slot.slotId) ?? slot.playerName,
      rank: ranked ? rank : null,
      receivedFromOthers: received.get(slot.slotId) ?? 0,
      sentToOthers: sent.get(slot.slotId) ?? 0,
      slotId: slot.slotId,
      slotName: slot.slotName,
      wasReleased: slot.wasReleased,
    };
  });
}
