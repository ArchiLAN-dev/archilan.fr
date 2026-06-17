"use client";

import { Gift, Info, Lightbulb, MapPin, MessageSquare } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";

import type { FeedEvent } from "./overlay-api";
import { eventInvolvesSlots, feedItemOrigin } from "./overlay-api";
import type { OverlayParams } from "./overlay-params";
import { useOverlayStream } from "./use-overlay-stream";

// Keep enough history that even a tall 4K source stays full; rows beyond the viewport are clipped.
const MAX_ROWS = 60;

// Fixed, readable row text size (px) - INDEPENDENT of the source dimensions, so shrinking the OBS
// source does not shrink the text. Rows always span the full width and a long line simply wraps onto
// more lines. `?scale` multiplies this for operators who want bigger/smaller text. The row is sized in
// `em` off this so badges, icons, padding and gaps all scale together.
const BASE_FONT_PX = 16;

// Types whose text reveals future item locations - masked when spoilers are off (the default) so a
// caster does not leak the seed on stream. The badge is kept; only the text is hidden.
const SPOILER_TYPES = new Set(["hint", "location-checked"]);

const TYPE_LABELS: Record<string, string> = {
  hint: "Indice",
  "item-received": "Objet",
  "location-checked": "Location",
  system: "Système",
  chat: "Chat",
};

const TYPE_CLASSES: Record<string, string> = {
  hint: "bg-amber-500/20 text-amber-300",
  "item-received": "bg-teal-500/20 text-teal-300",
  "location-checked": "bg-blue-500/20 text-blue-300",
  system: "bg-white/10 text-white/70",
  chat: "bg-white/10 text-white",
};

const TYPE_BORDER: Record<string, string> = {
  hint: "border-l-amber-500",
  "item-received": "border-l-teal-500",
  "location-checked": "border-l-blue-500",
  system: "border-l-white/30",
  chat: "border-l-white/30",
};

type IconComponent = React.ComponentType<{ className?: string; "aria-hidden"?: boolean }>;

const TYPE_ICONS: Record<string, IconComponent> = {
  hint: Lightbulb,
  "item-received": Gift,
  "location-checked": MapPin,
  system: Info,
  chat: MessageSquare,
};

type Row = FeedEvent & { id: number };

const DEMO_ROWS: FeedEvent[] = [
  {
    type: "item-received",
    text: "Link a reçu Progressive Sword",
    color: "plum",
    timestamp: "",
    item: { id: 1, name: "Progressive Sword" },
    location: { id: 2, name: "Bowser" },
    sender: { slot: 1, name: "Michel_M", game: "Mario 64" },
    receiver: { slot: 2, name: "Link", game: "Wind Waker" },
  },
  { type: "location-checked", text: "Samus a validé Missile Pack", color: "blue", timestamp: "" },
  { type: "hint", text: "Bombos Medallion est chez Mario", color: "salmon", timestamp: "" },
  { type: "chat", text: "gg !", color: "white", timestamp: "" },
];

export function LogOverlay({
  sessionId,
  params,
}: {
  sessionId: string;
  params: OverlayParams;
}) {
  const [rows, setRows] = useState<Row[]>([]);
  const idRef = useRef(0);

  const onEvent = useCallback(
    (event: FeedEvent) => {
      // `?slot=` keeps only events involving those slots (sender or receiver); global events
      // (chat/system) are dropped while a filter is active. Test events honor this too (they target the
      // selected slot).
      if (params.slots.length > 0 && !eventInvolvesSlots(event, params.slots)) return;
      setRows((prev) => [{ ...event, id: (idRef.current += 1) }, ...prev].slice(0, MAX_ROWS));
    },
    [params.slots],
  );

  useOverlayStream<FeedEvent>(sessionId, "feed", onEvent);

  useEffect(() => {
    if (!params.demo) return;
    setRows(DEMO_ROWS.map((event) => ({ ...event, id: (idRef.current += 1) })));
  }, [params.demo]);

  // Fixed text size (only `?scale` changes it); rows stay full-width and long lines wrap, so a smaller
  // source shows the same readable text with fewer rows rather than shrinking everything.
  const fontSize = BASE_FONT_PX * params.scale;

  // Grows from the bottom: newest row at the bottom of the screen, older ones pushed up. Horizontal
  // placement still honors `?pos` (left/center/right).
  const justify = params.pos.endsWith("left")
    ? "justify-start"
    : params.pos.endsWith("right")
      ? "justify-end"
      : "justify-center";

  return (
    <div className={`pointer-events-none fixed inset-0 flex items-end overflow-hidden p-[1.5vmin] ${justify}`}>
      <ul className="flex w-full flex-col-reverse gap-[0.45em]" style={{ fontSize }}>
        {rows.map((row) => {
          const label = TYPE_LABELS[row.type] ?? row.type;
          const badgeCls = TYPE_CLASSES[row.type] ?? "bg-white/10 text-white/70";
          const borderCls = TYPE_BORDER[row.type] ?? "border-l-white/30";
          const Icon = TYPE_ICONS[row.type];
          const masked = !params.spoilers && SPOILER_TYPES.has(row.type);
          const origin = feedItemOrigin(row);
          const primary = row.item?.name ?? row.text;

          return (
            <li
              className={`flex items-start gap-[0.6em] rounded border-l-4 bg-black/70 px-[0.8em] py-[0.4em] text-[1em] backdrop-blur-sm ${borderCls}`}
              key={row.id}
              style={{ animation: "log-row-in 0.35s cubic-bezier(0.16, 1, 0.3, 1) both" }}
            >
              <span
                className={`inline-flex shrink-0 items-center gap-[0.3em] rounded-full px-[0.6em] py-[0.15em] text-[0.72em] font-semibold ${badgeCls}`}
              >
                {Icon ? <Icon aria-hidden className="size-[1em]" /> : null}
                {label}
              </span>
              <span className="min-w-0 flex-1 break-words font-medium text-white drop-shadow">
                {masked ? "•••••" : primary}
                {!masked && origin ? (
                  <span className="ml-[0.4em] text-[0.82em] font-normal text-white/60">- {origin}</span>
                ) : null}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
