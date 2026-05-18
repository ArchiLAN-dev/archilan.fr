import Link from "next/link";
import type { PlayerHistory, PlayerProfile, RunHistoryEntry } from "./player-profile-api";

export function PlayerProfilePage({
  profile,
  history,
}: {
  profile: PlayerProfile;
  history: PlayerHistory | null;
}) {
  const displayName = profile.displayName ?? profile.slug;
  const entries = history?.data ?? [];
  const historyError = history === null;

  return (
    <article className="mx-auto w-full max-w-4xl grid gap-12">
      <header className="grid gap-6 border-b border-border pb-8">
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Profil joueur
          </p>
          <h1 className="mt-2 font-heading text-3xl font-bold text-foreground md:text-4xl">
            {displayName}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Membre depuis{" "}
            <time dateTime={profile.joinedAt}>{formatDate(profile.joinedAt)}</time>
          </p>
        </div>

        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatCard label="Runs" value={String(profile.stats.runsParticipated)} />
          <StatCard label="Objectifs" value={String(profile.stats.goalCompletions)} />
          <StatCard label="Checks" value={String(profile.stats.totalChecksDone)} />
          <StatCard
            label="Taux de complétion"
            value={
              profile.stats.runsParticipated > 0
                ? `${Math.round(profile.stats.goalCompletionRate * 100)}%`
                : "-"
            }
          />
        </div>
      </header>

      <section aria-labelledby="history-heading" className="grid gap-4">
        <h2 className="font-heading text-xl font-semibold text-foreground" id="history-heading">
          Historique des runs
        </h2>

        {historyError ? (
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
            {history !== null && history.meta.total > entries.length ? (
              <p className="text-xs text-muted-foreground text-center">
                Affichage des {entries.length} dernières runs ({history.meta.total} au total)
              </p>
            ) : null}
          </>
        )}
      </section>
    </article>
  );
}

function StatCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-border bg-surface p-4 text-center">
      <p className="text-2xl font-bold text-foreground">{value}</p>
      <p className="mt-1 text-xs text-muted-foreground">{label}</p>
    </div>
  );
}

function RunHistoryRow({ entry }: { entry: RunHistoryEntry }) {
  const muted = entry.isInvalidated;

  return (
    <Link
      className={`grid gap-3 rounded-lg border p-4 transition-colors hover:border-accent sm:grid-cols-[1fr_auto] ${
        muted ? "border-border/60 bg-surface/60" : "border-border bg-surface"
      }`}
      href={`/runs/${entry.sessionId}/resultats`}
    >
      <div className="grid gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <span
            className={`font-semibold ${muted ? "text-muted-foreground" : "text-foreground"}`}
          >
            {entry.eventName}
          </span>
          <StatusBadge entry={entry} />
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

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(new Date(iso));
}
