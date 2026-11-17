"use client";

import { useQuery } from "@tanstack/react-query";

import type { ConnectionSlot } from "@/components/slot-name-field";
import { fetchGameTrackerPacks, type GameTrackerPack } from "@/features/trackers/tracker-packs-api";

import { formatSlotRef } from "./slot-ref";
import type { SlotTracker } from "./tracker-slot-link";
import { TrackerScreen } from "./ui/tracker-screen";

/**
 * Story 54.10: the « Tracker » tab of a slot's progression page. It exists only when the viewer may connect this slot
 * (it is among the slots the connection screens give them, story 54.7) and its game has a pack on. The tracker then
 * connects by itself, from the slot's reference: no password in the address.
 */

/** The packs of a game change rarely: an admin enables one now and then. */
const PACKS_STALE_TIME = 5 * 60_000;

/** The tracker of the slot named `slotName` among the viewer's own connection slots, if it has one. */
export function ownSlotTracker(slots: readonly ConnectionSlot[] | undefined, slotName: string | null | undefined): SlotTracker | undefined {
  if (slots === undefined || slotName === null || slotName === undefined || slotName === "") return undefined;
  return slots.find((slot) => slot.name === slotName)?.tracker;
}

/** The packs offered for the slot's game; empty while unknown or when the slot has no tracker. */
export function useSlotTrackerPacks(tracker: SlotTracker | undefined): GameTrackerPack[] {
  const gameId = tracker?.gameId ?? null;
  const packs = useQuery({
    queryKey: ["game-tracker-packs", gameId],
    queryFn: () => fetchGameTrackerPacks(gameId ?? ""),
    staleTime: PACKS_STALE_TIME,
    enabled: gameId !== null,
  });
  return gameId === null ? [] : (packs.data ?? []);
}

type PanelProps = {
  tracker: SlotTracker;
  packs: GameTrackerPack[];
  gameName: string;
  /** False while another tab shows: the tracker stays mounted (pack loaded, connection kept) but hidden. */
  visible: boolean;
};

/** The tracker inside the page, mounted by the caller on the tab's first opening and kept afterwards. */
export function SlotTrackerPanel({ tracker, packs, gameName, visible }: PanelProps) {
  return (
    <div hidden={!visible}>
      <TrackerScreen
        embedded
        gameName={gameName}
        gameSlug={tracker.gameSlug}
        initialPackId={null}
        initialVariantUid={null}
        packs={packs}
        slotRef={formatSlotRef(tracker.ref)}
      />
    </div>
  );
}
