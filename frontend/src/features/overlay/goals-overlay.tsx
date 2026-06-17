"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { GoalCelebration } from "@/features/reachability/goal-celebration";
import type { PlayersSlot, PlayersState } from "./overlay-api";
import type { OverlayParams } from "./overlay-params";
import { useOverlayStream } from "./use-overlay-stream";

const GOAL_STATUS = 30;
const DEFAULT_DURATION_S = 12;

type Goal = { id: number; slotName: string; checksPercent: number };

function clampPercent(done: number, total: number): number {
  if (total <= 0) return 0;
  return Math.max(0, Math.min(100, Math.round((done / total) * 100)));
}

function matchesSlotFilter(slotKey: string, slot: PlayersSlot, filter: string[]): boolean {
  if (filter.length === 0) return true;
  return filter.includes(slotKey) || filter.includes(slot.slot_name);
}

export function GoalsOverlay({
  sessionId,
  params,
}: {
  sessionId: string;
  params: OverlayParams;
}) {
  const [current, setCurrent] = useState<Goal | null>(null);
  const seenRef = useRef<Set<string>>(new Set());
  const baselineDoneRef = useRef(false);
  const idRef = useRef(0);

  const onEvent = useCallback(
    (state: PlayersState) => {
      const slots = state.slots;
      if (!slots) return;

      // Test event from the overlay-test channel: celebrate the test slot at once, bypassing the
      // baseline/seen logic. It still honors the slot filter (the test targets the selected slot), so a
      // per-slot overlay only reacts to its own test.
      if (state.__test__ === true) {
        for (const [slotKey, slot] of Object.entries(slots)) {
          if (slot.client_status !== GOAL_STATUS || !slot.goal_reached_at) continue;
          if (!matchesSlotFilter(slotKey, slot, params.slots)) continue;
          setCurrent({
            id: (idRef.current += 1),
            slotName: slot.slot_name,
            checksPercent: clampPercent(slot.checks_done, slot.checks_total),
          });
          break;
        }
        return;
      }

      // The first snapshot carries every goal already reached before this overlay connected - record
      // those as a silent baseline so loading the source mid-session doesn't replay a flood of
      // celebrations. Only goals that arrive after the baseline are celebrated.
      const isBaseline = !baselineDoneRef.current;
      for (const [slotKey, slot] of Object.entries(slots)) {
        if (slot.client_status !== GOAL_STATUS || !slot.goal_reached_at) continue;
        if (!matchesSlotFilter(slotKey, slot, params.slots)) continue;
        if (seenRef.current.has(slotKey)) continue;
        seenRef.current.add(slotKey);
        if (isBaseline) continue;
        setCurrent({
          id: (idRef.current += 1),
          slotName: slot.slot_name,
          checksPercent: clampPercent(slot.checks_done, slot.checks_total),
        });
      }
      baselineDoneRef.current = true;
    },
    [params.slots],
  );

  useOverlayStream<PlayersState>(sessionId, "players", onEvent);

  // Demo mode: fire a fake celebration so the source can be positioned in OBS.
  useEffect(() => {
    if (!params.demo) return;
    const timer = setTimeout(() => {
      setCurrent({ id: (idRef.current += 1), slotName: "Démo Joueur", checksPercent: 100 });
    }, 400);
    return () => {
      clearTimeout(timer);
    };
  }, [params.demo]);

  // Auto-dismiss after the configured duration - nobody clicks "continuer" inside an OBS source.
  useEffect(() => {
    if (!current) return;
    const seconds = params.duration > 0 ? params.duration : DEFAULT_DURATION_S;
    const timer = setTimeout(() => {
      setCurrent(null);
    }, seconds * 1_000);
    return () => {
      clearTimeout(timer);
    };
  }, [current, params.duration]);

  if (!current) return null;

  return (
    <GoalCelebration
      bare
      checksPercent={current.checksPercent}
      gameName=""
      itemsPercent={0}
      key={current.id}
      onDismiss={() => {
        setCurrent(null);
      }}
      slotName={current.slotName}
    />
  );
}
