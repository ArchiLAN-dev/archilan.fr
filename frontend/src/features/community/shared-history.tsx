"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { RotateCcw, UserPlus } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchSharedHistory, sendFriendRequest, type SharedHistory } from "./community-friends-api";

const DATE = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", year: "numeric" });

function day(iso: string): string {
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? "" : DATE.format(at);
}

/** « 3 parties, du 2 mars 2026 au 1 mai 2026 », or the one date for a single session. */
export function sharedSummary(history: SharedHistory): string {
  if (history.count === 1) return `1 partie, le ${day(history.lastAt)}`;
  return `${history.count} parties, du ${day(history.firstAt)} au ${day(history.lastAt)}`;
}

/** « Items échangés : 12 envoyés, 8 reçus (depuis le 2 mars 2026) », or null without a kept feed. */
export function itemsLine(history: SharedHistory): string | null {
  const { sent, received, since } = history.items;
  if (since === null) return null;
  return `Items échangés : ${sent} envoyé${sent > 1 ? "s" : ""}, ${received} reçu${received > 1 ? "s" : ""} (depuis le ${day(since)})`;
}

/** The run creation, with this friend picked for the invitation sent once the run exists (story 43.1). */
export function relaunchHref(userId: string): string {
  return `/runs?inviter=${encodeURIComponent(userId)}`;
}

/**
 * « Vous avez joué ensemble » (story 43.9) on another member's profile, for a signed-in viewer who played with them.
 * Shown to a non-friend too (a reason to add them), never across a block (the API answers nothing then).
 */
export function SharedHistoryBlock({ slug, name }: { slug: string; name: string }) {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [requested, setRequested] = useState(false);
  const { data } = useQuery({
    queryKey: ["shared-history", slug],
    queryFn: () => fetchSharedHistory(slug),
    enabled: user !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (user === null || !data) return null;

  async function requestFriend() {
    if ((await sendFriendRequest(slug)) !== null) {
      setRequested(true);
      await queryClient.invalidateQueries({ queryKey: ["community-relationship", slug] });
    }
  }

  const items = itemsLine(data);

  return (
    <section aria-labelledby="shared-history" className="grid gap-4 rounded-2xl border border-border bg-surface/40 p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="grid gap-1">
          <h2 className="font-heading text-lg font-semibold text-foreground" id="shared-history">
            Vous avez joué ensemble
          </h2>
          <p className="text-sm text-muted-foreground">{sharedSummary(data)}</p>
          {items !== null ? <p className="text-sm text-muted-foreground">{items}</p> : null}
        </div>
        {data.isFriend ? (
          <Link
            className="inline-flex items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent-hover"
            href={relaunchHref(data.userId)}
          >
            <RotateCcw aria-hidden className="size-4" />
            Relancer une partie ensemble
          </Link>
        ) : requested ? (
          <p className="text-sm text-muted-foreground">Demande envoyée : une fois amis, tu pourras l&apos;inviter.</p>
        ) : (
          <button
            className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-4 py-2 text-sm font-semibold text-foreground hover:border-accent"
            onClick={() => {
              void requestFriend();
            }}
            title={`Ajoute ${name} en ami pour l'inviter dans une nouvelle partie`}
            type="button"
          >
            <UserPlus aria-hidden className="size-4" />
            Ajouter en ami pour rejouer
          </button>
        )}
      </div>
      <ul className="grid gap-1.5" role="list">
        {data.latest.map((session) => (
          <li className="flex items-center justify-between gap-3 text-sm" key={session.sessionId}>
            {session.recap ? (
              <Link className="min-w-0 truncate font-medium text-accent-text hover:underline" href={`/parties/${session.sessionId}`}>
                {session.title ?? "Partie"}
              </Link>
            ) : (
              <span className="min-w-0 truncate text-foreground">{session.title ?? "Partie"}</span>
            )}
            <span className="shrink-0 text-xs text-muted-foreground">
              {session.kind === "event" ? "Événement" : "Run perso"} · <time dateTime={session.playedAt}>{day(session.playedAt)}</time>
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}
