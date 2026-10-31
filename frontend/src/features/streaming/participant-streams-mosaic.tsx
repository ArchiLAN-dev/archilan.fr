"use client";

import { useState } from "react";
import { LayoutGrid, Maximize2, Volume2 } from "lucide-react";

import { cn } from "@/lib/utils";
import type { ParticipantStream } from "./participant-streams-api";

/** Most players side by side: beyond four, a browser tab struggles and the players get too small to follow. */
export const MOSAIC_MAX = 4;

/**
 * Story 47.3: the channels in the mosaic. At first the first lives; then the visitor's picks, minus the ones that went
 * offline since.
 */
export function reconcileMosaic(selection: readonly string[] | null, liveLogins: readonly string[], max = MOSAIC_MAX): string[] {
  if (selection === null) return liveLogins.slice(0, max);
  return selection.filter((login) => liveLogins.includes(login));
}

/** A click on a live card: out of the mosaic if it is in, else in, pushing the oldest out when full. */
export function toggleInMosaic(current: readonly string[], login: string, max = MOSAIC_MAX): string[] {
  if (current.includes(login)) return current.filter((item) => item !== login);
  const next = [...current, login];
  return next.slice(Math.max(0, next.length - max));
}

type Props = {
  streams: ParticipantStream[];
  focused: string | null;
  onFocus: (login: string | null) => void;
};

/** Story 47.3: up to four lives side by side, muted; one of them in big with its sound on demand. */
export function ParticipantStreamsMosaic({ streams, focused, onFocus }: Props) {
  if (streams.length === 0) return null;

  const big = focused !== null ? (streams.find((stream) => stream.twitchLogin === focused) ?? null) : null;
  if (big !== null) {
    const others = streams.filter((stream) => stream.twitchLogin !== big.twitchLogin);
    return (
      <div className="grid gap-3">
        <MosaicPlayer muted={false} stream={big} />
        <button
          className="inline-flex w-fit items-center gap-2 text-sm font-medium text-accent-text hover:underline"
          onClick={() => onFocus(null)}
          type="button"
        >
          <LayoutGrid aria-hidden className="size-4" />
          Revenir à la mosaïque
        </button>
        {others.length > 0 ? (
          <div className="grid grid-cols-3 gap-3">
            {others.map((stream) => (
              <MosaicTile key={stream.twitchLogin} onFocus={onFocus} stream={stream} />
            ))}
          </div>
        ) : null}
      </div>
    );
  }

  return (
    <div className={cn("grid gap-3", streams.length > 1 && "grid-cols-2")}>
      {streams.map((stream) => (
        <MosaicTile key={stream.twitchLogin} onFocus={onFocus} stream={stream} />
      ))}
    </div>
  );
}

function MosaicTile({ stream, onFocus }: { stream: ParticipantStream; onFocus: (login: string) => void }) {
  const name = stream.displayName ?? stream.slug;
  return (
    <div className="grid gap-1.5">
      <MosaicPlayer muted stream={stream} />
      <button
        aria-label={`Mettre ${name} en grand avec le son`}
        className="inline-flex min-w-0 items-center gap-1.5 text-left text-sm font-semibold text-foreground hover:text-accent-text"
        onClick={() => onFocus(stream.twitchLogin)}
        type="button"
      >
        <Maximize2 aria-hidden className="size-3.5 shrink-0" />
        <span className="truncate">{name}</span>
      </button>
    </div>
  );
}

function MosaicPlayer({ stream, muted }: { stream: ParticipantStream; muted: boolean }) {
  // The Twitch player wants the embedding hostname as `parent`; the mosaic only mounts after a click, client-side.
  const [parent] = useState(() => (typeof window === "undefined" ? "" : window.location.hostname));
  const name = stream.displayName ?? stream.slug;
  const src = `https://player.twitch.tv/?channel=${encodeURIComponent(stream.twitchLogin)}&parent=${encodeURIComponent(parent)}&muted=${muted ? "true" : "false"}`;
  return (
    <div className="relative aspect-video w-full overflow-hidden rounded-lg border border-border bg-surface">
      {parent !== "" ? <iframe allow="autoplay; fullscreen" allowFullScreen className="size-full" src={src} title={`Stream Twitch de ${name}`} /> : null}
      {!muted ? (
        <span className="pointer-events-none absolute left-2 top-2 inline-flex items-center gap-1 rounded bg-black/70 px-2 py-0.5 text-xs font-semibold text-white">
          <Volume2 aria-label="Avec le son" className="size-3" />
          {name}
        </span>
      ) : null}
    </div>
  );
}
