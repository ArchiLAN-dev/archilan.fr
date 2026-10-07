"use client";

import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Archive, ArchiveRestore, Gamepad2, Loader2, Square } from "lucide-react";
import { useState } from "react";

import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";

import { SHEET_LIST_CLASS, SheetEmpty, SheetPager, SheetSection, sheetPage } from "./admin-sheet-section";
import {
  fetchAdminUserGaming,
  setAdminUserRunArchived,
  stopAdminUserRun,
  type AdminUserGaming as Gaming,
  type AdminUserHistoryEntry,
  type AdminUserRun,
} from "./admin-users-api";

type RunStatus = { label: string; tone: "success" | "accent" | "warning" | "muted"; live: boolean; rank: 0 | 1 | 2 };

/**
 * A personal run's status as the sheet shows it (story 36.9), keyed by the values the run exposes. `live` runs
 * have a party that can still be stopped; `rank` orders the list: live first, then drafts, then the finished.
 */
const RUN_STATUS: Record<string, RunStatus> = {
  starting: { label: "Démarrage", tone: "accent", live: true, rank: 0 },
  active: { label: "En cours", tone: "success", live: true, rank: 0 },
  restarting: { label: "Redémarrage", tone: "accent", live: true, rank: 0 },
  idle: { label: "En veille", tone: "warning", live: true, rank: 0 },
  stopping: { label: "Arrêt en cours", tone: "warning", live: false, rank: 0 },
  draft: { label: "Brouillon", tone: "muted", live: false, rank: 1 },
  completed: { label: "Terminée", tone: "muted", live: false, rank: 2 },
  cancelled: { label: "Annulée", tone: "muted", live: false, rank: 2 },
};

export function runStatus(status: string): RunStatus {
  return RUN_STATUS[status] ?? { label: status === "" ? "Inconnu" : status, tone: "muted", live: false, rank: 1 };
}

/** A run of the sheet's list: the member's own, or one they joined. */
export type SheetRun = AdminUserRun & { owned: boolean };

/** Owned and joined runs in one list: live first, then drafts, then the finished; each group keeps its order. */
export function orderRuns(owned: AdminUserRun[], joined: AdminUserRun[]): SheetRun[] {
  const runs = [...owned.map((run) => ({ ...run, owned: true })), ...joined.map((run) => ({ ...run, owned: false }))];
  return runs
    .map((run, index) => ({ run, index }))
    .sort((a, b) => runStatus(a.run.status).rank - runStatus(b.run.status).rank || a.index - b.index)
    .map(({ run }) => run);
}

/** Only a live run the member owns can be stopped from their sheet (story 36.6), through its session. */
export function canStopRun(run: SheetRun): boolean {
  return run.owned && run.sessionId !== null && runStatus(run.status).live;
}

/** One finished party: the games the member played in the same session. */
export type HistoryGroup = { key: string; context: string | null; finishedAt: string | null; games: string[] };

/**
 * The finished-game history, one row per party instead of one per game (story 36.9). Rows of the same session
 * are merged; a row without a session stands alone. The history comes most recent first, and so do the parties.
 */
export function groupHistory(entries: AdminUserHistoryEntry[]): HistoryGroup[] {
  const groups = new Map<string, HistoryGroup>();
  entries.forEach((entry, index) => {
    const key = entry.sessionId ?? `entry-${index}`;
    const group = groups.get(key) ?? { key, context: entry.context, finishedAt: entry.finishedAt, games: [] };
    const game = entry.game ?? "Jeu inconnu";
    if (!group.games.includes(game)) group.games.push(game);
    groups.set(key, group);
  });
  return [...groups.values()];
}

const FINISHED: RunStatus = { label: "Terminée", tone: "muted", live: false, rank: 2 };

/**
 * One line of the « Runs et parties » list (story 36.9): a personal run, a finished party, or both at once - a
 * finished personal run is also a party of the history, through its session, and shows once.
 */
export type GameRow = {
  key: string;
  title: string;
  /** The run's page; a party from an event or a weekly has none to link. */
  href: string | null;
  status: RunStatus;
  invited: boolean;
  finishedAt: string | null;
  games: string[];
  run: SheetRun | null;
};

export type GameFilter = "all" | "live" | "draft" | "done" | "archived";

export const GAME_FILTERS: { id: GameFilter; label: string }[] = [
  { id: "all", label: "Tout" },
  { id: "live", label: "En cours" },
  { id: "draft", label: "Brouillons" },
  { id: "done", label: "Terminées" },
  { id: "archived", label: "Archivées" },
];

/** Statuses a run can be archived from (story 16.21): no party holding - or about to hold - a server. */
const ARCHIVABLE_STATUSES = ["draft", "completed", "cancelled"];

/** A run the member archived can come back; one they did not, only once its party is over. */
export function canArchiveRow(row: GameRow): boolean {
  return row.run !== null && (row.run.archived === true || ARCHIVABLE_STATUSES.includes(row.run.status));
}

/** Runs and history in one list: live first, then drafts, then the finished most recent first. */
export function buildGameRows(owned: AdminUserRun[], joined: AdminUserRun[], history: AdminUserHistoryEntry[]): GameRow[] {
  const parties = new Map(groupHistory(history).map((group) => [group.key, group]));
  const rows: GameRow[] = orderRuns(owned, joined).map((run) => {
    const party = run.sessionId !== null ? parties.get(run.sessionId) : undefined;
    if (party !== undefined) parties.delete(party.key);
    return {
      key: run.id,
      title: run.title === "" ? "Sans titre" : run.title,
      href: `/runs/${run.id}`,
      status: runStatus(run.status),
      invited: !run.owned,
      finishedAt: party?.finishedAt ?? null,
      // The party tells what was played; before it ends (draft, paused), what the member picked.
      games: party?.games ?? run.games,
      run,
    };
  });
  for (const party of parties.values()) {
    rows.push({ key: party.key, title: party.context ?? "Partie sans nom", href: null, status: FINISHED, invited: false, finishedAt: party.finishedAt, games: party.games, run: null });
  }

  return rows
    .map((row, index) => ({ row, index }))
    .sort((a, b) => {
      const byRank = a.row.status.rank - b.row.status.rank;
      if (byRank !== 0) return byRank;
      if (a.row.status.rank === 2) return (b.row.finishedAt ?? "").localeCompare(a.row.finishedAt ?? "") || a.index - b.index;
      return a.index - b.index;
    })
    .map(({ row }) => row);
}

/** An archived run (story 16.21) leaves the other filters, « Tout » included. */
export function gameFilterOf(row: GameRow): Exclude<GameFilter, "all"> {
  if (row.run?.archived === true) return "archived";
  return row.status.rank === 0 ? "live" : row.status.rank === 1 ? "draft" : "done";
}

function inFilter(row: GameRow, filter: GameFilter): boolean {
  const of = gameFilterOf(row);
  return filter === "all" ? of !== "archived" : of === filter;
}

/**
 * The member's game side on the admin sheet (story 36.4): progression, linked accounts, personal runs
 * and finished-game history. Personal runs are the part that had no admin surface at all (issue #387).
 */
export function AdminUserGaming({ userId, isSelf = false }: { userId: string; isSelf?: boolean }) {
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
        <h3 className="text-sm font-semibold text-foreground">Runs et parties</h3>
        {data.ownedRuns.length === 0 && data.joinedRuns.length === 0 && data.history.length === 0 ? (
          <SheetEmpty>Ce membre n&apos;a ni run personnelle ni partie terminée.</SheetEmpty>
        ) : (
          <GameList
            onStopped={async () => {
              await queryClient.invalidateQueries({ queryKey: ["admin-user-gaming", userId] });
            }}
            rows={buildGameRows(data.ownedRuns, data.joinedRuns, data.history)}
            readOnly={isSelf}
            userId={userId}
          />
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
      <dl className="grid gap-x-8 gap-y-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2">
        <div className="min-w-0">
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
        <div className="min-w-0">
          <dt className="text-xs uppercase tracking-wide text-muted-foreground">Steam</dt>
          <dd className="mt-1 truncate text-sm text-foreground">
            {steamProfile === null ? <span className="text-muted-foreground">Non renseigné</span> : steamProfile}
          </dd>
        </div>
      </dl>
    </div>
  );
}

/**
 * « Runs et parties » (story 36.9): the member's personal runs and finished parties in one list, filtered by
 * state and a few per page. Live ones come first, so the default view opens on what still runs.
 */
/** `readOnly`: an admin on their own sheet - the API refuses them their own account, so no action shows. */
export function GameList({ rows, userId, onStopped, readOnly = false }: { rows: GameRow[]; userId: string; onStopped: () => Promise<void>; readOnly?: boolean }) {
  const [filter, setFilter] = useState<GameFilter>("all");
  const [page, setPage] = useState(1);
  const filtered = rows.filter((row) => inFilter(row, filter));
  const current = sheetPage(filtered, page);

  function choose(next: GameFilter): void {
    setFilter(next);
    setPage(1);
  }

  return (
    <div className="grid gap-3">
      <div aria-label="Filtrer les runs et parties" className="flex flex-wrap gap-2" role="group">
        {GAME_FILTERS.map(({ id, label }) => {
          const count = rows.filter((row) => inFilter(row, id)).length;
          return (
            <button
              aria-pressed={filter === id}
              className={`inline-flex min-h-8 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
                filter === id ? "border-accent bg-accent/15 text-foreground" : "border-border text-muted-foreground hover:border-accent hover:text-foreground"
              }`}
              disabled={count === 0 && id !== "all"}
              key={id}
              onClick={() => choose(id)}
              type="button"
            >
              {label}
              <span className="tabular-nums text-xs text-muted-foreground">{count}</span>
            </button>
          );
        })}
      </div>
      {current.rows.length === 0 ? (
        <SheetEmpty>Rien dans ce filtre.</SheetEmpty>
      ) : (
        <ul className={SHEET_LIST_CLASS} role="list">
          {current.rows.map((row) => (
            <GameRowItem key={row.key} onStopped={onStopped} readOnly={readOnly} row={row} userId={userId} />
          ))}
        </ul>
      )}
      <SheetPager label="Pages des runs et parties" onPage={setPage} page={current.page} pages={current.pages} />
    </div>
  );
}

function GameRowItem({ row, userId, onStopped, readOnly }: { row: GameRow; userId: string; onStopped: () => Promise<void>; readOnly: boolean }) {
  // A party without a run is not linked, for the same reason as the audit timeline: a finished session only has
  // a recap when one was built, and a dead link is worse than a plain label.
  return (
    <li className="grid gap-1.5 px-4 py-2.5">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        {row.href !== null ? (
          <Link className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground hover:text-accent-text" href={row.href}>
            {row.title}
          </Link>
        ) : (
          <span className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground">{row.title}</span>
        )}
        {row.invited ? <span className="rounded border border-border px-1.5 text-xs text-muted-foreground">Invité</span> : null}
        {row.finishedAt !== null ? (
          <time className="shrink-0 text-xs text-muted-foreground" dateTime={row.finishedAt}>
            {formatDate(row.finishedAt)}
          </time>
        ) : null}
        <StatusPill status={row.status} />
        {!readOnly && row.run !== null && canStopRun(row.run) ? <StopRunButton onStopped={onStopped} runId={row.run.id} runTitle={row.title} userId={userId} /> : null}
        {!readOnly && row.run !== null && canArchiveRow(row) ? <ArchiveRunButton archived={row.run.archived === true} onChanged={onStopped} runId={row.run.id} userId={userId} /> : null}
      </div>
      {row.games.length > 0 ? (
        <ul aria-label="Jeux" className="flex flex-wrap gap-1.5" role="list">
          {row.games.map((game) => (
            <li className="rounded bg-surface-2 px-2 py-0.5 text-xs text-muted-foreground" key={game}>
              {game}
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  );
}

const PILL_TONE: Record<RunStatus["tone"], string> = {
  success: "border-success/40 bg-success/10 text-success",
  accent: "border-accent/40 bg-accent/10 text-accent-text",
  warning: "border-warning/40 bg-warning/10 text-warning",
  muted: "border-border text-muted-foreground",
};

function StatusPill({ status }: { status: RunStatus }) {
  return <span className={`shrink-0 rounded-full border px-2 py-0.5 text-xs font-medium ${PILL_TONE[status.tone]}`}>{status.label}</span>;
}

function StopRunButton({
  userId,
  runId,
  runTitle,
  onStopped,
}: {
  userId: string;
  runId: string;
  runTitle: string;
  onStopped: () => Promise<void>;
}) {
  const [pending, setPending] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function stop(): Promise<void> {
    setPending(true);
    setError(null);
    const message = await stopAdminUserRun(userId, runId);
    if (message === null) {
      await onStopped();
    } else {
      setError(message);
    }
    setPending(false);
    setConfirming(false);
  }

  return (
    <>
      <button
        className="inline-flex min-h-7 shrink-0 items-center gap-1.5 rounded border border-border px-2 text-xs font-semibold text-foreground transition-colors hover:border-danger/50 hover:text-danger disabled:opacity-40"
        disabled={pending}
        onClick={() => setConfirming(true)}
        type="button"
      >
        {pending ? <Loader2 aria-hidden className="size-3.5 animate-spin" /> : <Square aria-hidden className="size-3.5" />}
        Arrêter
      </button>
      {error !== null ? <p className="basis-full text-xs text-danger">{error}</p> : null}
      <ConfirmDialog
        confirmLabel="Arrêter la partie"
        description={`La partie en cours de « ${runTitle === "" ? "Sans titre" : runTitle} » sera terminée et archivée.`}
        onConfirm={() => void stop()}
        onOpenChange={(open) => {
          if (!open && !pending) setConfirming(false);
        }}
        open={confirming}
        pending={pending}
        icon={Square}
        title="Arrêter la partie ?"
        tone="danger"
      />
    </>
  );
}

function Panel({ children }: { children: React.ReactNode }) {
  return (
    <SheetSection
      description="Progression, comptes liés, runs personnelles et parties terminées."
      icon={Gamepad2}
      id="jeu"
      title="Jeu"
    >
      {children}
    </SheetSection>
  );
}

function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "-";

  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(date);
}

/** Story 16.21: put the run away in the member's own list, or bring it back. Reversible, so no confirmation. */
function ArchiveRunButton({ userId, runId, archived, onChanged }: { userId: string; runId: string; archived: boolean; onChanged: () => Promise<void> }) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function toggle(): Promise<void> {
    setPending(true);
    setError(null);
    const message = await setAdminUserRunArchived(userId, runId, !archived);
    if (message === null) {
      await onChanged();
    } else {
      setError(message);
    }
    setPending(false);
  }

  return (
    <>
      <button
        className="inline-flex min-h-7 shrink-0 items-center gap-1.5 rounded border border-border px-2 text-xs font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-40"
        disabled={pending}
        onClick={() => void toggle()}
        type="button"
      >
        {pending ? (
          <Loader2 aria-hidden className="size-3.5 animate-spin" />
        ) : archived ? (
          <ArchiveRestore aria-hidden className="size-3.5" />
        ) : (
          <Archive aria-hidden className="size-3.5" />
        )}
        {archived ? "Désarchiver" : "Archiver"}
      </button>
      {error !== null ? <p className="basis-full text-xs text-danger">{error}</p> : null}
    </>
  );
}
