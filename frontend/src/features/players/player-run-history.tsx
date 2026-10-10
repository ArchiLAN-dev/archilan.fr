"use client";

import { useQuery } from "@tanstack/react-query";
import { Lock } from "lucide-react";
import Link from "next/link";

import { useAuth } from "@/features/auth/auth-context";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchOwnPlayerHistory, type PlayerHistory, type RunHistoryEntry } from "./player-profile-api";

/**
 * The run history of a profile. The server render is anonymous, so it holds only what visitors see (story
 * 32.22: private runs only once their recap is published). When the signed-in member looks at their own
 * profile, the history is read again with their cookies, private runs included and marked.
 */
export function PlayerRunHistory({ slug, history }: { slug: string; history: PlayerHistory | null }) {
  const { user } = useAuth();
  const isOwner = user !== null && user.slug === slug;
  const { data: own } = useQuery({
    queryKey: ["player-own-history", slug],
    queryFn: () => fetchOwnPlayerHistory(slug),
    enabled: isOwner,
    staleTime: DEFAULT_STALE_TIME,
  });

  const shown = isOwner && own ? own : history;
  const entries = shown?.data ?? [];

  return (
    <section aria-labelledby="history-heading" className="grid gap-4">
      <h2 className="font-heading text-xl font-semibold text-foreground" id="history-heading">
        Historique des runs
      </h2>

      {shown === null ? (
        <p className="text-muted-foreground">
          L&apos;historique est temporairement indisponible.
        </p>
      ) : entries.length === 0 ? (
        <p className="text-muted-foreground">Aucune run terminée pour l&apos;instant.</p>
      ) : (
        <>
          <div className="grid gap-2">
            {entries.map((entry) => (
              <RunHistoryRow entry={entry} key={`${entry.sessionId}-${entry.game}`} />
            ))}
          </div>
          {shown.meta.total > entries.length ? (
            <p className="text-xs text-muted-foreground text-center">
              Affichage des {entries.length} dernières runs ({shown.meta.total} au total)
            </p>
          ) : null}
        </>
      )}
    </section>
  );
}

function RunHistoryRow({ entry }: { entry: RunHistoryEntry }) {
  const muted = entry.isInvalidated;

  // A row links to its recap only when the server says this viewer may open it (story 32.20):
  // weekly runs have none, and a private run's recap is owner/participants-only.
  const baseClassName = `grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_auto] ${
    muted ? "border-border/60 bg-surface/60" : "border-border bg-surface"
  }`;

  const inner = (
    <>
      <div className="grid gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <span
            className={`font-semibold ${muted ? "text-muted-foreground" : "text-foreground"}`}
          >
            {entry.eventName}
          </span>
          <StatusBadge entry={entry} />
          {entry.isPrivate === true ? (
            <span
              className="inline-flex shrink-0 items-center gap-1 rounded border border-border px-2 py-0.5 text-xs font-semibold text-muted-foreground"
              title="Récap non publié : les autres membres ne voient pas cette partie sur ton profil."
            >
              <Lock aria-hidden className="size-3" />
              Privée
            </span>
          ) : null}
        </div>

        <p className={`text-sm ${muted ? "text-muted-foreground/70" : "text-muted-foreground"}`}>
          {entry.game}
          {entry.finishedAt ? (
            <>
              {" · "}
              <time dateTime={entry.finishedAt}>{formatDate(entry.finishedAt)}</time>
            </>
          ) : null}
        </p>
      </div>

      <dl
        className={`flex gap-4 text-sm sm:flex-col sm:items-end sm:gap-1 ${
          muted ? "text-muted-foreground/70" : "text-muted-foreground"
        }`}
      >
        <div className="flex gap-1">
          <dt className="sr-only">Checks</dt>
          <dd>
            <span className="font-semibold text-foreground">{entry.checksDone}</span> checks
          </dd>
        </div>
        <div className="flex gap-1">
          <dt className="sr-only">Items reçus</dt>
          <dd>
            <span className="font-semibold text-foreground">{entry.itemsReceived}</span> items
          </dd>
        </div>
      </dl>
    </>
  );

  if (entry.isWeekly || entry.recapAccessible !== true) {
    return <div className={baseClassName}>{inner}</div>;
  }

  return (
    <Link className={`${baseClassName} transition-colors hover:border-accent`} href={`/parties/${entry.sessionId}`}>
      {inner}
    </Link>
  );
}

function StatusBadge({ entry }: { entry: RunHistoryEntry }) {
  if (entry.isInvalidated) {
    return (
      <span className="shrink-0 rounded border border-amber-500/50 px-2 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
        Forfait
      </span>
    );
  }

  if (entry.goalReachedAt !== null) {
    return (
      <span className="shrink-0 rounded border border-success/50 px-2 py-0.5 text-xs font-semibold text-success">
        Objectif atteint
      </span>
    );
  }

  return (
    <span className="shrink-0 rounded border border-muted-foreground/40 px-2 py-0.5 text-xs font-semibold text-muted-foreground">
      Incomplet
    </span>
  );
}

export function formatDate(iso: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(new Date(iso));
}
