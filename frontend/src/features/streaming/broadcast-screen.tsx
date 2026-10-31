"use client";

import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { parseOverlayParams } from "@/features/overlay/overlay-params";
import { cn } from "@/lib/utils";
import { fetchBroadcastStreams, type ParticipantStream } from "./participant-streams-api";

export type BroadcastScreenMode = "mosaique" | "rotation";

export type BroadcastScreenParams = {
  eventId: string | null;
  mode: BroadcastScreenMode;
  /** Most players on screen at once: 1 to 9, 4 by default (beyond that OBS and the browser heat up). */
  max: number;
  /** Seconds before the next group (mosaic over the cap) or the next featured stream (rotation). */
  seconds: number;
  /** 6-hex chroma key without '#', or null for a transparent document. */
  bg: string | null;
};

/** A player who goes live, or stops, reaches OBS within a minute. */
export const BROADCAST_SCREEN_REFRESH_MS = 60 * 1000;

/** At most three thumbnails fit beside the featured stream (a quarter of the width each). */
const ROTATION_THUMBNAILS = 3;

const EVENT_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;

function boundedInteger(raw: string | null, min: number, max: number, fallback: number): number {
  const n = Number(raw);
  if (raw === null || !Number.isInteger(n)) return fallback;
  return Math.min(max, Math.max(min, n));
}

/** Story 47.2: the régie screen's settings, read from the OBS source URL. */
export function parseBroadcastScreenParams(sp: URLSearchParams): BroadcastScreenParams {
  const rawEvent = sp.get("event");
  return {
    eventId: rawEvent !== null && EVENT_ID_PATTERN.test(rawEvent) ? rawEvent : null,
    mode: sp.get("mode") === "rotation" ? "rotation" : "mosaique",
    max: boundedInteger(sp.get("max"), 1, 9, 4),
    seconds: boundedInteger(sp.get("duree"), 5, 600, 30),
    bg: parseOverlayParams(sp).bg,
  };
}

/** The link to paste in OBS. */
export function broadcastScreenUrl(appUrl: string, eventId: string, mode: BroadcastScreenMode = "mosaique"): string {
  const params = new URLSearchParams({ event: eventId });
  if (mode === "rotation") params.set("mode", "rotation");
  return `${appUrl}/o/streams?${params.toString()}`;
}

/** The mosaic for `count` players: 1, 2 side by side, 2 x 2, 3 x 2 or 3 x 3, so 16:9 cells fill a 16:9 source. */
export function mosaicColumns(count: number): 1 | 2 | 3 {
  if (count <= 1) return 1;
  if (count <= 4) return 2;
  return 3;
}

/** The players on screen at `tick`: everyone under the cap, else one group of `max` after the other. */
export function mosaicGroup<T>(items: readonly T[], max: number, tick: number): T[] {
  if (items.length <= max) return [...items];
  const groups = Math.ceil(items.length / max);
  const start = (tick % groups) * max;
  return items.slice(start, start + max);
}

/** Rotation mode at `tick`: one featured player, the next ones as thumbnails (at most `max - 1`, and 3). */
export function rotationSlots<T>(items: readonly T[], max: number, tick: number): { featured: T | null; thumbnails: T[] } {
  if (items.length === 0) return { featured: null, thumbnails: [] };
  const index = tick % items.length;
  const others = [...items.slice(index + 1), ...items.slice(0, index)];
  return { featured: items[index] ?? null, thumbnails: others.slice(0, Math.min(max - 1, ROTATION_THUMBNAILS)) };
}

function useTick(active: boolean, seconds: number): number {
  const [tick, setTick] = useState(0);
  useEffect(() => {
    if (!active) return;
    const id = window.setInterval(() => setTick((current) => current + 1), seconds * 1000);
    return () => window.clearInterval(id);
  }, [active, seconds]);
  return tick;
}

/** Story 47.2: the players live on the event, for an OBS browser source. Nothing at all when nobody is live. */
export function BroadcastScreen({ params }: { params: BroadcastScreenParams }) {
  const { eventId } = params;
  const { data } = useQuery({
    queryKey: ["broadcast-streams", eventId],
    queryFn: async () => (eventId === null ? [] : ((await fetchBroadcastStreams(eventId)) ?? Promise.reject(new Error("broadcast read failed")))),
    staleTime: BROADCAST_SCREEN_REFRESH_MS,
    refetchInterval: BROADCAST_SCREEN_REFRESH_MS,
    // A failed read keeps the players on air; the next interval tries again.
    retry: false,
  });
  const streams = data ?? [];
  const rotates = params.mode === "rotation" ? streams.length > 1 : streams.length > params.max;
  const tick = useTick(rotates, params.seconds);

  // The screen composites over the régie's scene: a transparent document, or the chroma key asked for.
  const background = params.bg !== null ? `#${params.bg}` : "transparent";
  useEffect(() => {
    const prevBody = document.body.style.background;
    const prevHtml = document.documentElement.style.background;
    document.body.style.background = background;
    document.documentElement.style.background = background;
    return () => {
      document.body.style.background = prevBody;
      document.documentElement.style.background = prevHtml;
    };
  }, [background]);

  if (params.mode === "rotation") {
    return <BroadcastRotation {...rotationSlots(streams, params.max, tick)} />;
  }
  return <BroadcastMosaic streams={mosaicGroup(streams, params.max, tick)} />;
}

const COLUMN_CLASSES: Record<1 | 2 | 3, string> = { 1: "grid-cols-1", 2: "grid-cols-2", 3: "grid-cols-3" };

export function BroadcastMosaic({ streams }: { streams: ParticipantStream[] }) {
  if (streams.length === 0) return null;
  return (
    <div className="fixed inset-0 flex items-center justify-center overflow-hidden">
      <div className={cn("grid w-full", COLUMN_CLASSES[mosaicColumns(streams.length)])}>
        {streams.map((stream) => (
          <BroadcastCell key={stream.twitchLogin} stream={stream} />
        ))}
      </div>
    </div>
  );
}

export function BroadcastRotation({ featured, thumbnails }: { featured: ParticipantStream | null; thumbnails: ParticipantStream[] }) {
  if (featured === null) return null;
  return (
    <div className="fixed inset-0 flex items-center justify-center overflow-hidden">
      <div className={cn("grid w-full items-start", thumbnails.length > 0 ? "grid-cols-[3fr_1fr]" : "grid-cols-1")}>
        <BroadcastCell key={featured.twitchLogin} stream={featured} />
        {thumbnails.length > 0 ? (
          <div className="grid">
            {thumbnails.map((stream) => (
              <BroadcastCell compact key={stream.twitchLogin} stream={stream} />
            ))}
          </div>
        ) : null}
      </div>
    </div>
  );
}

/** One muted, autoplaying Twitch player with the member's name: OBS mixes the scene's sound, not this page. */
export function BroadcastCell({ stream, compact = false }: { stream: ParticipantStream; compact?: boolean }) {
  // The Twitch player wants the embedding hostname as `parent`; cells only mount client-side, once the list is read.
  const [parent] = useState(() => (typeof window === "undefined" ? "" : window.location.hostname));
  const name = stream.displayName ?? stream.slug;
  const src = `https://player.twitch.tv/?channel=${encodeURIComponent(stream.twitchLogin)}&parent=${encodeURIComponent(parent)}&muted=true&autoplay=true`;
  return (
    <figure className="relative aspect-video overflow-hidden bg-black">
      {parent !== "" ? <iframe allow="autoplay" className="size-full border-0" src={src} title={`Stream Twitch de ${name}`} /> : null}
      <figcaption
        className={cn(
          "pointer-events-none absolute bottom-0 left-0 rounded-tr-lg bg-black/70 font-semibold text-white",
          compact ? "px-2 py-0.5 text-sm" : "px-4 py-1.5 text-xl",
        )}
      >
        {name}
      </figcaption>
    </figure>
  );
}
