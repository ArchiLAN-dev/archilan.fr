"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Radio, Users } from "lucide-react";
import { FaTwitch } from "react-icons/fa6";
import { SESSION_STALE_TIME } from "@/lib/query-client";
import {
  fetchParticipantStreams,
  type ParticipantStream,
  type ParticipantStreamKind,
} from "./participant-streams-api";
import { ParticipantStreamEmbed } from "./participant-stream-embed";

type Props = {
  kind: ParticipantStreamKind;
  id: string;
  // "hide" (default): render nothing when no participant streams - for inline placement on event/run pages.
  // "message": render the card with an empty-state message - for a dedicated tab that must not be blank.
  emptyState?: "hide" | "message";
  // "card" (default): standalone bordered card with a heading (event / personal-run pages).
  // "section": a borderless in-card row showing only currently-live streamers, hidden entirely when
  //   nobody is live - used inside the weekly game category card (the parent gates on the run being active).
  variant?: "card" | "section";
  // When true, render every stream with no cap or overflow link (used by the dedicated /streams page).
  showAll?: boolean;
};

const viewerFormatter = new Intl.NumberFormat("fr-FR");

// Above this many cards, the widget caps the list and links to the dedicated /streams page.
const MAX_VISIBLE_STREAMS = 10;

/**
 * Per-session widget listing participants' Twitch channels (story 7.7). Live channels are shown first;
 * clicking a card loads it into a single shared player above the list (swappable). On small viewports a
 * click opens twitch.tv in a new tab instead.
 */
export function ParticipantStreams({ kind, id, emptyState = "hide", variant = "card", showAll = false }: Props) {
  const { data = [] } = useQuery({
    queryKey: ["participant-streams", kind, id],
    queryFn: () => fetchParticipantStreams(kind, id),
    staleTime: SESSION_STALE_TIME,
    refetchInterval: 60_000,
  });

  const [activeChannel, setActiveChannel] = useState<string | null>(null);

  const live = data.filter((stream) => stream.live);
  const offline = data.filter((stream) => !stream.live);
  // Reconcile the selection against the refetched list: drop it if the chosen streamer left the list.
  const effectiveActive =
    activeChannel !== null && data.some((stream) => stream.twitchLogin === activeChannel) ? activeChannel : null;

  // Cap the rendered cards (live first); the remainder is reachable via the dedicated /streams page.
  const shownLive = showAll ? live : live.slice(0, MAX_VISIBLE_STREAMS);
  const offlineBudget = showAll ? offline.length : Math.max(0, MAX_VISIBLE_STREAMS - shownLive.length);
  const shownOffline = offline.slice(0, offlineBudget);
  const streamsHref = `/streams/${kind}/${id}`;

  function handleSelect(login: string): void {
    if (typeof window === "undefined") {
      return;
    }
    // Shared embed on usable viewports; on small screens fall back to opening the channel.
    if (window.matchMedia("(min-width: 640px)").matches) {
      setActiveChannel(login);
    } else {
      window.open(`https://twitch.tv/${login}`, "_blank", "noopener,noreferrer");
    }
  }

  const sharedEmbed = effectiveActive !== null && (
    <div className="hidden sm:block">
      <ParticipantStreamEmbed channel={effectiveActive} />
    </div>
  );

  // ── "section" variant: live-only, in-card row, hidden entirely when nobody is live ──
  if (variant === "section") {
    if (live.length === 0) {
      return null;
    }

    return (
      <div className="border-t border-border">
        <div className="flex items-center gap-2 px-5 py-3 text-sm font-medium text-accent-text">
          <Radio aria-hidden className="size-3.5" />
          Participants en direct
          <span className="rounded-full border border-border px-2 py-0.5 text-xs font-normal text-muted-foreground">
            {live.length}
          </span>
        </div>
        <div className="grid gap-3 px-5 pb-4">
          {sharedEmbed}
          <ul className="grid gap-2 sm:grid-cols-2">
            {shownLive.map((stream) => (
              <StreamCard
                active={stream.twitchLogin === effectiveActive}
                key={stream.userId}
                onSelect={handleSelect}
                stream={stream}
              />
            ))}
          </ul>
          {live.length > shownLive.length && (
            <Link className="text-sm font-medium text-accent-text hover:underline" href={streamsHref}>
              Voir les {live.length} streams en direct →
            </Link>
          )}
        </div>
      </div>
    );
  }

  // ── "card" variant (default): standalone card, live first then offline ──
  if (data.length === 0 && emptyState === "hide") {
    return null;
  }

  const hiddenCount = data.length - shownLive.length - shownOffline.length;

  return (
    <section
      aria-labelledby="participant-streams-heading"
      className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:p-6"
    >
      <div className="flex items-center gap-2">
        <FaTwitch aria-hidden className="size-4 text-accent-text" />
        <h2 className="font-heading text-lg font-semibold text-foreground" id="participant-streams-heading">
          Streams des participants
        </h2>
      </div>

      {data.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Aucun participant ne diffuse sa partie sur Twitch pour le moment.
        </p>
      ) : (
        <>
          {sharedEmbed}

          {shownLive.length > 0 && (
            <div className="grid gap-2">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-accent-text">En direct</p>
              <ul className="grid gap-2 sm:grid-cols-2">
                {shownLive.map((stream) => (
                  <StreamCard
                    active={stream.twitchLogin === effectiveActive}
                    key={stream.userId}
                    onSelect={handleSelect}
                    stream={stream}
                  />
                ))}
              </ul>
            </div>
          )}

          {shownOffline.length > 0 && (
            <div className="grid gap-2">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                Chaînes des participants
              </p>
              <ul className="grid gap-2 sm:grid-cols-2">
                {shownOffline.map((stream) => (
                  <StreamCard
                    active={stream.twitchLogin === effectiveActive}
                    key={stream.userId}
                    onSelect={handleSelect}
                    stream={stream}
                  />
                ))}
              </ul>
            </div>
          )}

          {hiddenCount > 0 && (
            <Link className="text-sm font-medium text-accent-text hover:underline" href={streamsHref}>
              Voir les {data.length} streams →
            </Link>
          )}
        </>
      )}
    </section>
  );
}

function StreamAvatar({ avatarUrl, live }: { avatarUrl: string | null; live: boolean }) {
  const [failed, setFailed] = useState(false);
  const ring = live ? "ring-2 ring-[color:var(--color-danger)]" : "";

  if (avatarUrl !== null && !failed) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- external Twitch CDN avatar, not a local asset
      <img
        alt=""
        aria-hidden
        className={`size-8 shrink-0 rounded-full bg-surface object-cover ${ring}`}
        onError={() => setFailed(true)}
        src={avatarUrl}
      />
    );
  }

  return (
    <span
      className={`flex size-8 shrink-0 items-center justify-center rounded-full ${ring} ${
        live ? "bg-accent/20 text-accent-text" : "bg-surface text-muted-foreground"
      }`}
    >
      <FaTwitch aria-hidden className="size-4" />
    </span>
  );
}

type StreamCardProps = {
  stream: ParticipantStream;
  active: boolean;
  onSelect: (login: string) => void;
};

function StreamCard({ stream, active, onSelect }: StreamCardProps) {
  const name = stream.displayName ?? stream.slug;

  return (
    <li>
      <button
        aria-pressed={active}
        className={`flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-left transition-colors ${
          active
            ? "border-accent bg-accent/10"
            : "border-border bg-background hover:border-accent"
        }`}
        onClick={() => onSelect(stream.twitchLogin)}
        type="button"
      >
        <StreamAvatar avatarUrl={stream.avatarUrl} live={stream.live} />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-semibold text-foreground">{name}</span>
          <span className="block truncate text-xs text-muted-foreground">@{stream.twitchLogin}</span>
        </span>
        {stream.live ? (
          <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[color:var(--color-danger)]/15 px-2 py-0.5 text-xs font-semibold text-[color:var(--color-danger)]">
            <Radio aria-hidden className="size-3" />
            En direct
            {stream.viewerCount !== null && (
              <span className="inline-flex items-center gap-0.5 font-normal">
                <Users aria-hidden className="size-3" />
                {viewerFormatter.format(stream.viewerCount)}
              </span>
            )}
          </span>
        ) : (
          <span className="shrink-0 text-xs text-muted-foreground/70">Hors ligne</span>
        )}
      </button>
    </li>
  );
}
