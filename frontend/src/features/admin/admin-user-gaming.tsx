"use client";

import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Square } from "lucide-react";
import { useState } from "react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  fetchAdminUserGaming,
  stopAdminUserRun,
  type AdminUserGaming as Gaming,
  type AdminUserHistoryEntry,
  type AdminUserRun,
} from "./admin-users-api";

const RUN_STATUS: Record<string, string> = {
  draft: "Brouillon",
  starting: "Démarrage",
  running: "En cours",
  paused: "En pause",
  finished: "Terminée",
  cancelled: "Annulée",
};

/**
 * The member's game side on the admin sheet (story 36.4): progression, linked accounts, personal runs
 * and finished-game history. Personal runs are the part that had no admin surface at all (issue #387).
 */
export function AdminUserGaming({ userId }: { userId: string }) {
  const queryClient = useQueryClient();
  const { data, isPending } = useQuery({
    queryKey: ["admin-user-gaming", userId],
    queryFn: () => fetchAdminUserGaming(userId),
    staleTime: DEFAULT_STALE_TIME,
  });

  if (isPending) {
    return (
      <Panel>
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      </Panel>
    );
  }

  if (data === null || data === undefined) {
    return (
      <Panel>
        <p className="text-sm text-muted-foreground">Impossible de charger l&apos;activité de jeu.</p>
      </Panel>
    );
  }

  return (
    <Panel>
      <Progress gaming={data} />
      <Accounts gaming={data} />

      <div className="grid gap-2">
        <h3 className="text-sm font-semibold text-foreground">Runs personnelles</h3>
        {data.ownedRuns.length === 0 && data.joinedRuns.length === 0 ? (
          <Empty>Ce membre n&apos;a ni créé ni rejoint de run personnelle.</Empty>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            <RunList runs={data.ownedRuns} title="Dont il est propriétaire" userId={userId} onStopped={async () => { await queryClient.invalidateQueries({ queryKey: ["admin-user-gaming", userId] }); }} />
            <RunList runs={data.joinedRuns} title="Qu'il a rejointes" />
          </div>
        )}
      </div>

      <div className="grid gap-2">
        <h3 className="text-sm font-semibold text-foreground">Parties terminées</h3>
        {data.history.length === 0 ? (
          <Empty>Aucune partie terminée.</Empty>
        ) : (
          <ul className="grid gap-2" role="list">
            {data.history.map((entry, index) => (
              <HistoryRow entry={entry} key={`${entry.sessionId ?? "x"}-${index}`} />
            ))}
          </ul>
        )}
      </div>
    </Panel>
  );
}

function Progress({ gaming }: { gaming: Gaming }) {
  const stats: { label: string; value: number }[] = [
    { label: "Niveau", value: gaming.progress.level },
    { label: "XP", value: gaming.progress.xp },
    { label: "Runs", value: gaming.progress.runsParticipated },
    { label: "Objectifs", value: gaming.progress.goalCompletions },
    { label: "Checks", value: gaming.progress.totalChecksDone },
    { label: "Succès", value: gaming.progress.achievementsUnlocked },
  ];

  return (
    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      {stats.map((stat) => (
        <div className="rounded-lg border border-border bg-surface px-3 py-2 text-center" key={stat.label}>
          <dt className="text-xs uppercase tracking-wide text-muted-foreground">{stat.label}</dt>
          <dd className="mt-0.5 font-heading text-lg font-bold text-foreground">
            {new Intl.NumberFormat("fr-FR").format(stat.value)}
          </dd>
        </div>
      ))}
    </dl>
  );
}

function Accounts({ gaming }: { gaming: Gaming }) {
  const { discordId, discordUsername, steamProfile } = gaming.accounts;

  return (
    <div className="grid gap-2">
      <h3 className="text-sm font-semibold text-foreground">Comptes liés</h3>
      <dl className="grid gap-3 sm:grid-cols-2">
        <div className="rounded-lg border border-border bg-surface px-4 py-3">
          <dt className="text-xs uppercase tracking-wide text-muted-foreground">Discord</dt>
          <dd className="mt-1 text-sm text-foreground">
            {discordId === null ? (
              <span className="text-muted-foreground">Non lié</span>
            ) : (
              <>
                {discordUsername ?? "Compte lié"}{" "}
                <span className="font-mono text-xs text-muted-foreground">({discordId})</span>
              </>
            )}
          </dd>
        </div>
        <div className="rounded-lg border border-border bg-surface px-4 py-3">
          <dt className="text-xs uppercase tracking-wide text-muted-foreground">Steam</dt>
          <dd className="mt-1 truncate text-sm text-foreground">
            {steamProfile === null ? <span className="text-muted-foreground">Non renseigné</span> : steamProfile}
          </dd>
        </div>
      </dl>
    </div>
  );
}

function RunList({
  title,
  runs,
  userId,
  onStopped,
}: {
  title: string;
  runs: AdminUserRun[];
  /** Only the runs the member OWNS can be stopped from their sheet (story 36.6). */
  userId?: string;
  onStopped?: () => Promise<void>;
}) {
  return (
    <div className="grid gap-2">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{title}</p>
      {runs.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucune</p>
      ) : (
        <ul className="grid gap-2" role="list">
          {runs.map((run) => (
            <li className="rounded-lg border border-border bg-surface px-3 py-2" key={run.id}>
              <Link className="text-sm font-semibold text-accent-text hover:underline" href={`/runs/${run.id}`}>
                {run.title === "" ? "Sans titre" : run.title}
              </Link>
              <p className="text-xs text-muted-foreground">{RUN_STATUS[run.status] ?? run.status}</p>
              {userId !== undefined && onStopped !== undefined && run.sessionId !== null ? (
                <StopRunButton onStopped={onStopped} runId={run.id} userId={userId} />
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function StopRunButton({
  userId,
  runId,
  onStopped,
}: {
  userId: string;
  runId: string;
  onStopped: () => Promise<void>;
}) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function stop(): Promise<void> {
    if (!window.confirm("Arrêter la partie en cours de cette run ? Elle sera terminée et archivée.")) return;

    setPending(true);
    setError(null);
    const message = await stopAdminUserRun(userId, runId);
    if (message === null) {
      await onStopped();
    } else {
      setError(message);
    }
    setPending(false);
  }

  return (
    <>
      <button
        className="mt-1 inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-2 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-40"
        disabled={pending}
        onClick={() => void stop()}
        type="button"
      >
        {pending ? <Loader2 aria-hidden className="size-3.5 animate-spin" /> : <Square aria-hidden className="size-3.5" />}
        Arrêter la partie
      </button>
      {error !== null ? <p className="mt-1 text-xs text-danger">{error}</p> : null}
    </>
  );
}

function HistoryRow({ entry }: { entry: AdminUserHistoryEntry }) {
  // Not linked, for the same reason as the audit timeline: a finished session only has a recap when
  // one was built, and a dead link is worse than a plain label.
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface px-4 py-2">
      <span className="min-w-0 text-sm text-foreground">
        {entry.game ?? "Jeu inconnu"}
        {entry.context !== null ? <span className="text-muted-foreground"> · {entry.context}</span> : null}
      </span>
      {entry.finishedAt !== null ? (
        <time className="shrink-0 text-xs text-muted-foreground" dateTime={entry.finishedAt}>
          {formatDate(entry.finishedAt)}
        </time>
      ) : null}
    </li>
  );
}

function Panel({ children }: { children: React.ReactNode }) {
  return (
    <section className="grid gap-4">
      <h2 className="font-heading text-xl font-semibold text-foreground">Jeu</h2>
      {children}
    </section>
  );
}

function Empty({ children }: { children: React.ReactNode }) {
  return (
    <p className="rounded-lg border border-border bg-surface px-4 py-6 text-center text-sm text-muted-foreground">
      {children}
    </p>
  );
}

function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "-";

  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(date);
}
