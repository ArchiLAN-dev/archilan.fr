"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { ItemToast } from "@/features/reachability/item-toast";
import type { FeedEvent } from "./overlay-api";
import { actorMatchesSlots, feedItemOrigin } from "./overlay-api";
import type { OverlayParams } from "./overlay-params";
import { useOverlayStream } from "./use-overlay-stream";
import { useViewport } from "./use-viewport";

// AP item classification bitmask reused by ItemToast: 1 = progression, 2 = useful, 4 = trap, 0 = filler.
const TRAP = 4;
const PROGRESSION = 1;
const USEFUL = 2;
const FILLER = 0;

// The feed event carries a named `color` (not the numeric AP flags); map the common Archipelago
// PrintJSON colors to a toast variant, falling back to filler so an unknown color still renders.
function deriveFlags(event: FeedEvent): number {
  const color = (event.color ?? "").toLowerCase();
  if (["salmon", "red", "crimson", "orangered"].includes(color)) return TRAP;
  if (["plum", "magenta", "violet", "purple"].includes(color)) return PROGRESSION;
  if (["slateblue", "cyan", "blue", "teal"].includes(color)) return USEFUL;
  return FILLER;
}

// Natural (unscaled) footprint of the toast card; used to compute how much to scale it so it fills the
// OBS source. The card grows away from this base; `?scale` fine-tunes on top.
const NATURAL_WIDTH = 360;
const NATURAL_HEIGHT = 120;

type Toast = { id: number; name: string; flags: number; subtitle?: string };

const DEMO_TOASTS: Omit<Toast, "id">[] = [
  { name: "Progressive Sword", flags: PROGRESSION, subtitle: "Bowser - Mario 64 (Michel_M)" },
  { name: "Bomb Bag", flags: USEFUL, subtitle: "Temple de l'Eau - OoT (Sarah)" },
  { name: "Ice Trap", flags: TRAP, subtitle: "Forêt Kokiri - OoT (Sarah)" },
  { name: "10 Rupees", flags: FILLER, subtitle: "Plaine d'Hyrule - ALttP (Léa)" },
];

export function NotificationsOverlay({
  sessionId,
  params,
}: {
  sessionId: string;
  params: OverlayParams;
}) {
  const [queue, setQueue] = useState<Toast[]>([]);
  const idRef = useRef(0);

  const onEvent = useCallback(
    (event: FeedEvent) => {
      if (event.type !== "item-received") return;
      // `?slot=` keeps only items received BY those slots (a streamer's own notifications, or a group).
      // Test events honor this too (they target the selected slot), so the filter is faithfully exercised.
      if (params.slots.length > 0 && !actorMatchesSlots(event.receiver, params.slots)) {
        return;
      }
      setQueue((prev) => [
        ...prev,
        {
          id: (idRef.current += 1),
          name: event.item?.name ?? event.text,
          flags: deriveFlags(event),
          subtitle: feedItemOrigin(event) ?? undefined,
        },
      ]);
    },
    [params.slots],
  );

  useOverlayStream<FeedEvent>(sessionId, "feed", onEvent);

  // Demo mode: stage a few fake notifications so the source can be positioned in OBS without a live
  // session. Ids are computed in the effect (never during render) to keep render pure.
  useEffect(() => {
    if (!params.demo) return;
    const timers = DEMO_TOASTS.map((toast, i) =>
      setTimeout(() => {
        setQueue((prev) => [...prev, { id: (idRef.current += 1), ...toast }]);
      }, i * 1_200),
    );
    return () => {
      timers.forEach(clearTimeout);
    };
  }, [params.demo]);

  const current = queue[0];

  // Scale the toast so it fills the OBS source (whole page), bounded by both axes so it never overflows.
  const { width, height } = useViewport();
  const fillScale = Math.min((width * 0.92) / NATURAL_WIDTH, (height * 0.9) / NATURAL_HEIGHT) * params.scale;

  if (!current) return null;

  return (
    <ItemToast
      fillScale={fillScale}
      flags={current.flags}
      itemName={current.name}
      key={current.id}
      onDone={() => {
        setQueue((prev) => prev.slice(1));
      }}
      subtitle={current.subtitle}
      variant="fill"
    />
  );
}
