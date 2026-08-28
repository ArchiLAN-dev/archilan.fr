"use client";

import Link from "next/link";

import { LiveMark } from "@/features/streaming/live-mark";
import { MemberAvatar } from "./member-avatar";
import type { DirectoryRow } from "./community-directory-api";

type Props = {
  row: DirectoryRow;
  /** Rank shown on ranked listings; null everywhere else. */
  rank?: number | null;
};

/**
 * A member as every community surface shows them (story 30.38): avatar, name, level and progress toward
 * the next one, plus the live "en jeu" dot. Replaces the name + "Niv. X" line the directory used to draw.
 *
 * Le badge Twitch (story 30.39) est un lien vers la chaîne, donc il vit **à côté** du lien de la carte,
 * pas dedans : deux ancres imbriquées ne sont pas du HTML valide. Il est posé en absolu sur la droite,
 * et la carte réserve la place correspondante quand le membre diffuse.
 */
export function MemberCard({ row, rank = null }: Props) {
  const name = row.displayName ?? row.slug;
  const progress =
    row.xpForNextLevel > 0 ? Math.min(100, Math.round((row.xpIntoLevel / row.xpForNextLevel) * 100)) : 0;

  const liveLogin = row.liveTwitchLogin;

  return (
    <span className="relative flex h-full">
      <Link
        className={`flex h-full flex-1 items-center gap-3 rounded-lg border border-border bg-surface p-3 transition-colors hover:border-accent${liveLogin !== null ? " pr-20" : ""}`}
        href={`/joueurs/${row.slug}`}
      >
        {rank !== null ? (
          <span className="w-6 shrink-0 text-center font-heading text-sm font-bold text-muted-foreground">{rank}</span>
        ) : null}

        {/* Positioning context only (no clip) so the "En jeu" badge can overflow the avatar circle. */}
        <span className="relative inline-flex size-10 shrink-0">
          <MemberAvatar avatarUrl={row.avatarUrl} name={name} />
          {row.playing ? (
            <span
              aria-label="En jeu"
              className="absolute -bottom-0.5 -right-0.5 z-10 size-3 animate-pulse rounded-full border-2 border-surface bg-emerald-400"
              title="En jeu"
            />
          ) : null}
        </span>

        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-semibold text-foreground">{name}</span>
          <span className="mt-1 flex items-center gap-2">
            <span className="shrink-0 text-xs text-muted-foreground">Niv. {row.level}</span>
            <span aria-hidden className="h-1 min-w-0 flex-1 overflow-hidden rounded-full bg-border">
              <span className="block h-full rounded-full bg-accent" style={{ width: `${progress}%` }} />
            </span>
          </span>
        </span>
      </Link>

      {liveLogin !== null ? (
        <a
          aria-label={`${name} est en direct sur Twitch - voir le stream (nouvel onglet)`}
          className="absolute right-3 top-1/2 z-10 inline-flex -translate-y-1/2 items-center gap-1.5 transition-opacity duration-200 hover:opacity-75"
          href={`https://twitch.tv/${liveLogin}`}
          rel="noopener noreferrer"
          target="_blank"
        >
          <LiveMark />
        </a>
      ) : null}
    </span>
  );
}
