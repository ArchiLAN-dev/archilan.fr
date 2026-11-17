"use client";

import { useQuery } from "@tanstack/react-query";
import { useEffect, useRef } from "react";

import { parseSlotRef, type SlotRef } from "../slot-ref";
import type { SlotConnection, SlotCredentials } from "../tracker-slot-api";

/**
 * Story 54.7: the tracker opened on a slot reads the slot's connection with the player's session and connects by
 * itself. A party not launched yet is read again until it runs.
 */
export type SlotState = SlotConnection | { kind: "loading" } | { kind: "invalid" } | { kind: "wrongGame" };

/** How often a party not launched yet is read again; a running one, for a relaunch on another port. */
export const WAITING_REFRESH_MS = 15_000;
export const RUNNING_REFRESH_MS = 60_000;

export function useSlotConnection(
  slotRef: string | null,
  gameSlug: string | null,
  fetchSlot: (ref: SlotRef) => Promise<SlotConnection>,
): SlotState | null {
  const ref = parseSlotRef(slotRef);
  const query = useQuery({
    queryKey: ["tracker-slot", slotRef],
    queryFn: () => (ref === null ? Promise.resolve<SlotConnection>({ kind: "denied" }) : fetchSlot(ref)),
    enabled: ref !== null,
    staleTime: WAITING_REFRESH_MS,
    refetchOnWindowFocus: false,
    refetchInterval: (current) => {
      const kind = current.state.data?.kind;
      if (kind === "denied") return false;
      return kind === "ready" ? RUNNING_REFRESH_MS : WAITING_REFRESH_MS;
    },
  });
  if (slotRef === null) return null;
  if (ref === null) return { kind: "invalid" };
  const data = query.data;
  if (data === undefined) return { kind: "loading" };
  if ((data.kind === "ready" || data.kind === "waiting") && data.gameSlug !== null && gameSlug !== null && data.gameSlug !== gameSlug) {
    return { kind: "wrongGame" };
  }
  return data;
}

type RoomControl = { connectRoom: (credentials: SlotCredentials) => void };

/**
 * Connects once per address and slot, when the pack is ready and the party runs. A player who disconnects stays
 * disconnected; a relaunch on another address connects again.
 */
export function useAutoConnect(slot: SlotState | null, packReady: boolean, room: RoomControl): void {
  const done = useRef<string | null>(null);
  const { connectRoom } = room;
  useEffect(() => {
    if (!packReady || slot?.kind !== "ready") return;
    const { credentials } = slot;
    const key = `${credentials.address}\n${credentials.slot}`;
    if (done.current === key) return;
    done.current = key;
    // Out of the effect's body: connecting sets the room's state.
    queueMicrotask(() => connectRoom(credentials));
  }, [packReady, slot, connectRoom]);
}

const MESSAGES: Record<Exclude<SlotState["kind"], "ready">, { text: string; tone: "muted" | "warning" }> = {
  loading: { text: "Lecture de votre partie…", tone: "muted" },
  waiting: { text: "La partie n'est pas lancée : le tracker se connectera tout seul quand elle le sera.", tone: "muted" },
  denied: {
    text: "Ce slot n'est pas le vôtre, ou la partie n'existe plus. Le tracker reste utilisable à la main.",
    tone: "warning",
  },
  error: {
    text: "Impossible de lire votre partie pour l'instant (êtes-vous connecté au site ?). Nouvel essai dans quelques secondes.",
    tone: "warning",
  },
  invalid: { text: "Le lien vers votre slot n'est pas valide. Le tracker reste utilisable à la main.", tone: "warning" },
  wrongGame: { text: "Ce slot joue à un autre jeu que celui de ce tracker.", tone: "warning" },
};

export function SlotBanner({ slot }: { slot: SlotState }) {
  if (slot.kind === "ready") return null;
  const message = MESSAGES[slot.kind];
  return (
    <p
      className={`rounded-lg border px-3 py-2 text-sm ${
        message.tone === "warning" ? "border-warning/40 bg-warning/10 text-foreground" : "border-border bg-surface text-muted-foreground"
      }`}
      role="status"
    >
      {message.text}
    </p>
  );
}
