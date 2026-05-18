"use client";

import { Bot, CheckCircle2, ExternalLink, Loader2, RefreshCw, WifiOff, XCircle } from "lucide-react";
import { useState } from "react";

import {
  type DiscordBotStatus,
  type DiscordBotUser,
  type DiscordBotUsersResponse,
  fetchDiscordBotStatus,
  fetchDiscordBotUsers,
  postDiscordResync,
} from "./discord-bot-api";

const PAGE_SIZE = 50;

type StatusState = { kind: "ready"; status: DiscordBotStatus } | { kind: "error" };

type UsersState =
  | { kind: "loading"; users: DiscordBotUser[]; total: number; page: number }
  | { kind: "ready"; users: DiscordBotUser[]; total: number; page: number }
  | { kind: "error" };

type ResyncState =
  | { kind: "idle" }
  | { kind: "pending" }
  | { kind: "success"; queued: number }
  | { kind: "error" };

type Props = {
  initialStatus: DiscordBotStatus | null;
  initialUsers: DiscordBotUsersResponse | null;
};

export function AdminDiscordDashboard({ initialStatus, initialUsers }: Props) {
  const [statusState, setStatusState] = useState<StatusState>(
    initialStatus !== null ? { kind: "ready", status: initialStatus } : { kind: "error" },
  );
  const [usersState, setUsersState] = useState<UsersState>(
    initialUsers !== null
      ? {
          kind: "ready",
          users: initialUsers.data,
          total: initialUsers.meta.total,
          page: initialUsers.meta.page,
        }
      : { kind: "error" },
  );
  const [resyncState, setResyncState] = useState<ResyncState>({ kind: "idle" });

  async function handleResync() {
    setResyncState({ kind: "pending" });
    try {
      const result = await postDiscordResync();
      if (result === null) {
        setResyncState({ kind: "error" });
        return;
      }

      setResyncState({ kind: "success", queued: result.queued });
      const [status, users] = await Promise.all([
        fetchDiscordBotStatus(),
        fetchDiscordBotUsers(1, PAGE_SIZE),
      ]);
      setStatusState(status !== null ? { kind: "ready", status } : { kind: "error" });
      setUsersState(
        users !== null
          ? { kind: "ready", users: users.data, total: users.meta.total, page: users.meta.page }
          : { kind: "error" },
      );
    } catch {
      setResyncState({ kind: "error" });
    }
  }

  async function handleLoadMore() {
    if (usersState.kind === "error" || usersState.users.length >= usersState.total) {
      return;
    }

    const currentState = usersState;
    setUsersState({ ...currentState, kind: "loading" });
    const users = await fetchDiscordBotUsers(currentState.page + 1, PAGE_SIZE);

    if (users === null) {
      setUsersState({ kind: "error" });
      return;
    }

    setUsersState({
      kind: "ready",
      users: [...currentState.users, ...users.data],
      total: users.meta.total,
      page: users.meta.page,
    });
  }

  return (
    <section className="grid w-full gap-8 px-4 py-10">
      <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
        <div>
          <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
            Backoffice
          </p>
          <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
            Discord Bot
          </h1>
          <p className="mt-3 max-w-2xl text-muted-foreground">
            Statut du bot et synchronisation des roles Discord.
          </p>
        </div>
        <div className="flex flex-wrap items-start gap-2">
          {resyncState.kind === "success" ? (
            <span className="inline-flex items-center gap-1.5 rounded border border-success/50 bg-success/10 px-3 py-2 text-sm text-success">
              <CheckCircle2 aria-hidden="true" className="size-4" />
              {resyncState.queued}&nbsp;synchro
              {resyncState.queued > 1 ? "s" : ""}&nbsp;lancee
              {resyncState.queued > 1 ? "s" : ""}
            </span>
          ) : resyncState.kind === "error" ? (
            <span className="inline-flex items-center gap-1.5 rounded border border-danger/50 bg-danger/10 px-3 py-2 text-sm text-danger">
              <XCircle aria-hidden="true" className="size-4" />
              Echec de la resynchronisation
            </span>
          ) : null}
          <button
            className="inline-flex min-h-10 items-center gap-2 rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
            disabled={resyncState.kind === "pending"}
            type="button"
            onClick={() => void handleResync()}
          >
            {resyncState.kind === "pending" ? (
              <Loader2 aria-hidden="true" className="size-4 animate-spin" />
            ) : (
              <RefreshCw aria-hidden="true" className="size-4" />
            )}
            Resynchroniser tout
          </button>
        </div>
      </header>

      <BotStatusCard state={statusState} />
      <UsersTable state={usersState} onLoadMore={() => void handleLoadMore()} />
    </section>
  );
}

function BotStatusCard({ state }: { state: StatusState }) {
  if (state.kind === "error") {
    return (
      <div className="rounded border border-danger/30 bg-surface p-6">
        <p className="text-sm text-danger">Impossible de recuperer le statut du bot.</p>
      </div>
    );
  }

  const { status } = state;

  return (
    <div className="grid gap-6 rounded border border-border bg-surface p-6 md:grid-cols-3 lg:grid-cols-5">
      <div className="flex items-start gap-3">
        {status.botOnline ? (
          <Bot aria-hidden="true" className="mt-0.5 size-6 shrink-0 text-success" />
        ) : (
          <WifiOff aria-hidden="true" className="mt-0.5 size-6 shrink-0 text-danger" />
        )}
        <div>
          <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
            Statut
          </p>
          <p
            className={`mt-1 font-heading text-2xl font-bold ${status.botOnline ? "text-success" : "text-danger"}`}
          >
            {status.botOnline ? "En ligne" : "Hors ligne"}
          </p>
        </div>
      </div>
      <div>
        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          Serveur
        </p>
        <p className="mt-1 font-heading text-2xl font-bold text-foreground">
          {status.guildName ?? <span className="text-xl text-muted-foreground">-</span>}
        </p>
      </div>
      <div>
        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          Membres Discord
        </p>
        <p className="mt-1 font-heading text-2xl font-bold text-foreground">
          {status.memberCount !== null ? (
            new Intl.NumberFormat("fr-FR").format(status.memberCount)
          ) : (
            <span className="text-xl text-muted-foreground">-</span>
          )}
        </p>
      </div>
      <div>
        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          Adhérents actifs
        </p>
        <p className="mt-1 font-heading text-2xl font-bold text-foreground">
          {new Intl.NumberFormat("fr-FR").format(status.activeMemberCount)}
        </p>
      </div>
      <div>
        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          Installation
        </p>
        {status.inviteUrl ? (
          <a
            className="mt-2 inline-flex min-h-9 items-center gap-2 rounded border border-border bg-surface-2 px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            href={status.inviteUrl}
            rel="noopener noreferrer"
            target="_blank"
          >
            <ExternalLink aria-hidden="true" className="size-4" />
            Inviter le bot
          </a>
        ) : (
          <p className="mt-2 text-sm text-muted-foreground">Client Discord non configure</p>
        )}
      </div>
    </div>
  );
}

function UsersTable({ state, onLoadMore }: { state: UsersState; onLoadMore: () => void }) {
  if (state.kind === "loading" && state.users.length === 0) {
    return (
      <div className="animate-pulse overflow-hidden border border-border bg-surface">
        <div className="h-10 border-b border-border bg-surface-2" />
        {[1, 2, 3, 4, 5].map((i) => (
          <div className="h-14 border-b border-border/50 bg-surface-2/40 last:border-b-0" key={i} />
        ))}
      </div>
    );
  }

  if (state.kind === "error") {
    return (
      <div className="rounded border border-danger/30 bg-surface p-6">
        <p className="text-sm text-danger">
          Impossible de charger la liste des utilisateurs Discord.
        </p>
      </div>
    );
  }

  if (state.users.length === 0) {
    return (
      <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
        <Bot aria-hidden="true" className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">
          Aucun utilisateur n&apos;a encore lie son compte Discord.
        </p>
      </div>
    );
  }

  const canLoadMore = state.users.length < state.total;

  return (
    <div className="grid gap-2">
      <div className="flex items-baseline gap-2">
        <h2 className="font-heading text-lg font-semibold text-foreground">
          Utilisateurs Discord
        </h2>
        <span className="text-sm text-muted-foreground">({state.total} au total)</span>
      </div>
      <div className="overflow-x-auto border border-border bg-surface">
        <table className="w-full min-w-[800px] border-collapse text-left text-sm">
          <thead className="border-b border-border text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">Utilisateur</th>
              <th className="px-4 py-3 font-medium">Compte Discord</th>
              <th className="px-4 py-3 font-medium">Roles ArchiLAN</th>
              <th className="px-4 py-3 font-medium">Derniere synchro</th>
              <th className="px-4 py-3 font-medium">Erreur</th>
            </tr>
          </thead>
          <tbody>
            {state.users.map((user) => (
              <tr className="border-b border-border last:border-b-0" key={user.id}>
                <td className="px-4 py-4">
                  <p className="font-semibold text-foreground">{user.email}</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {user.displayName ?? "-"}
                  </p>
                </td>
                <td className="px-4 py-4">
                  <p className="text-foreground">{user.discordUsername ?? "-"}</p>
                  <p className="mt-0.5 font-mono text-xs text-muted-foreground">{user.discordId}</p>
                </td>
                <td className="px-4 py-4">
                  <div className="flex flex-wrap gap-1">
                    {user.roles.map((role) => (
                      <span
                        className="inline-flex items-center rounded border border-border bg-surface-2 px-2 py-0.5 text-xs font-medium text-muted-foreground"
                        key={role}
                      >
                        {role}
                      </span>
                    ))}
                  </div>
                </td>
                <td className="px-4 py-4 text-muted-foreground">
                  {user.discordRoleSyncedAt ? (
                    <time dateTime={user.discordRoleSyncedAt}>
                      {new Intl.DateTimeFormat("fr-FR", {
                        dateStyle: "short",
                        timeStyle: "short",
                      }).format(new Date(user.discordRoleSyncedAt))}
                    </time>
                  ) : (
                    <span>-</span>
                  )}
                </td>
                <td className="px-4 py-4">
                  {user.discordSyncError ? (
                    <span
                      className="inline-flex max-w-[200px] truncate rounded border border-danger/40 bg-danger/10 px-2 py-0.5 text-xs text-danger"
                      title={user.discordSyncError}
                    >
                      {user.discordSyncError}
                    </span>
                  ) : (
                    <span className="text-xs text-success">OK</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {canLoadMore ? (
        <div className="flex justify-center pt-2">
          <button
            className="inline-flex min-h-10 items-center gap-2 rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50"
            disabled={state.kind === "loading"}
            type="button"
            onClick={onLoadMore}
          >
            {state.kind === "loading" ? (
              <Loader2 aria-hidden="true" className="size-4 animate-spin" />
            ) : (
              <RefreshCw aria-hidden="true" className="size-4" />
            )}
            Charger plus
          </button>
        </div>
      ) : null}
    </div>
  );
}
