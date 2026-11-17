"use client";

import { useQuery } from "@tanstack/react-query";
import { Map as MapIcon } from "lucide-react";

import { fetchGameTrackerPacks } from "@/features/trackers/tracker-packs-api";

import { trackerSlotHref, type SlotRef } from "./slot-ref";

/**
 * Story 54.7: « Ouvrir le tracker » next to a slot's connection details, when its game has a pack. A new tab, so the
 * player keeps the tracker next to the game. The address names the slot, never the password.
 */
export type SlotTracker = { gameId: string; gameSlug: string; ref: SlotRef };

/** The packs of a game change rarely: an admin enables one now and then. */
const PACKS_STALE_TIME = 5 * 60_000;

export function TrackerSlotLink({ tracker, slotName }: { tracker: SlotTracker; slotName: string }) {
  const packs = useQuery({
    queryKey: ["game-tracker-packs", tracker.gameId],
    queryFn: () => fetchGameTrackerPacks(tracker.gameId),
    staleTime: PACKS_STALE_TIME,
  });
  if ((packs.data?.length ?? 0) === 0) return null;
  return (
    <a
      aria-label={`Ouvrir le tracker de ${slotName} dans un nouvel onglet`}
      className="inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent"
      href={trackerSlotHref(tracker.gameSlug, tracker.ref)}
      rel="noopener"
      target="_blank"
    >
      <MapIcon aria-hidden="true" className="size-4" />
      Ouvrir le tracker
    </a>
  );
}
