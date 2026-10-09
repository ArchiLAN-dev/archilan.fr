"use client";

import { useEffect, useId, useState } from "react";
import type { FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Archive, Gamepad2, Loader2, Plus } from "lucide-react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { useAuth } from "@/features/auth/auth-context";
import { fetchFriends } from "@/features/community/community-friends-api";
import { replaceLocationParam, useLocationParam } from "@/lib/use-location-param";
import { fetchMyRuns, setRunArchivedForMe } from "./personal-runs-api";
import { PersonalRunCard } from "./personal-run-card";
import { MyRunInvitations } from "./run-invitations";
import { sendRunInvitations } from "./run-invitations-api";
import type { PersonalRun, PersonalRunStatus } from "./types";

// Statuses that should appear in the collapsed "Annulées" section
const COLLAPSED_STATUSES: PersonalRunStatus[] = ["cancelled"];

// Display order for non-collapsed groups
const STATUS_ORDER: PersonalRunStatus[] = [
  "active",
  "starting",
  "stopping",
  "idle",
  "restarting",
  "draft",
  "completed",
];

/** Story 43.9: the friend to invite into the run about to be created. */
const INVITE_PARAM = "inviter";

const GROUP_LABELS: Partial<Record<PersonalRunStatus, string>> = {
  active: "En cours",
  starting: "En démarrage",
  stopping: "En arrêt",
  idle: "En pause",
  restarting: "Redémarrage…",
  draft: "Brouillons",
  completed: "Terminées",
};

export function PersonalRunsListPage({ embedded = false }: { embedded?: boolean }) {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [title, setTitle] = useState("");
  const [titleError, setTitleError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [showCancelled, setShowCancelled] = useState(false);
  const [restartingId, setRestartingId] = useState<string | null>(null);
  const [showArchived, setShowArchived] = useState(false);
  const [archivingId, setArchivingId] = useState<string | null>(null);
  const [archiveError, setArchiveError] = useState<string | null>(null);
  const formId = useId();

  // Story 43.9: « Relancer une partie ensemble » opens the form with this friend picked; the invitation (43.1) is only
  // sent once the run is created. Someone who is not (or no longer) a friend is ignored.
  const inviteeId = useLocationParam(INVITE_PARAM);
  const friendsQuery = useQuery({
    queryKey: ["community-friends"],
    queryFn: fetchFriends,
    enabled: inviteeId !== null && user !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const invitee = inviteeId === null ? null : (friendsQuery.data?.friends.find((friend) => friend.userId === inviteeId) ?? null);
  const formOpen = showForm || invitee !== null;

  function closeForm() {
    setShowForm(false);
    setTitle("");
    setTitleError(null);
    if (inviteeId !== null) replaceLocationParam(INVITE_PARAM, null);
  }

  // fetchMyRuns never throws (failures are encoded as null), so the query never errors and - like
  // the old effect - never retries.
  const runsQuery = useQuery({
    queryKey: ["personal-runs", "mine"],
    queryFn: fetchMyRuns,
    enabled: !authLoading && user !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  // Redirect unauthenticated users - only once the session has finished resolving, so a cold load
  // doesn't bounce an authenticated user to /connexion before the profile resolves.
  useEffect(() => {
    if (!authLoading && !user) {
      router.push("/connexion?returnTo=/runs");
    }
  }, [authLoading, user, router]);

  async function handleRestart(run: PersonalRun) {
    if (run.sessionId === null || run.pausedWithoutSave) return;

    setRestartingId(run.id);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/sessions/${run.sessionId}/restart`, { method: "POST" });
      if (res.ok) {
        await queryClient.invalidateQueries({ queryKey: ["personal-runs", "mine"] });
      }
    } finally {
      setRestartingId(null);
    }
  }

  /** Story 16.21: put the run away in my list, or bring it back. */
  async function handleArchive(run: PersonalRun, archived: boolean) {
    setArchivingId(run.id);
    setArchiveError(null);
    const error = await setRunArchivedForMe(run.id, archived);
    if (error === null) {
      await queryClient.invalidateQueries({ queryKey: ["personal-runs", "mine"] });
    } else {
      setArchiveError(error);
    }
    setArchivingId(null);
  }

  async function handleCreate(e: FormEvent) {
    e.preventDefault();
    const trimmed = title.trim();

    if (!trimmed) {
      setTitleError("Le titre est requis.");
      return;
    }
    if (trimmed.length > 80) {
      setTitleError("Le titre ne peut pas dépasser 80 caractères.");
      return;
    }

    setTitleError(null);
    setCreating(true);

    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ title: trimmed }),
      });

      if (!res.ok) {
        const payload = (await res.json()) as { error?: { details?: { title?: string[] } } };
        const apiErr = payload.error?.details?.title?.[0] ?? "Impossible de créer la partie.";
        setTitleError(apiErr);
        return;
      }

      const payload = (await res.json()) as { data: PersonalRun };
      if (invitee !== null) {
        // Best effort: the run exists either way, and the owner can still invite from its page.
        await sendRunInvitations(payload.data.id, [invitee.userId]);
      }
      // Mark the cached list stale so the next visit to /runs refetches it (the old effect refetched
      // on every mount).
      void queryClient.invalidateQueries({ queryKey: ["personal-runs", "mine"] });
      router.push(`/runs/${payload.data.id}`);
    } catch {
      setTitleError("Erreur réseau.");
    } finally {
      setCreating(false);
    }
  }

  if (authLoading || runsQuery.isPending) {
    return (
      <div className={embedded ? undefined : "mx-auto max-w-content"}>
        <div className="grid gap-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-20 animate-pulse rounded-lg border border-border bg-surface" />
          ))}
        </div>
      </div>
    );
  }

  const mine = runsQuery.data ?? null;

  if (mine === null) {
    return (
      <div className={embedded ? undefined : "mx-auto max-w-content"}>
        <p className="text-sm text-muted-foreground">
          Impossible de charger tes parties pour le moment.
        </p>
      </div>
    );
  }

  // Story 16.21: an archived run leaves its section for « Archivées », owned or joined alike.
  const archivedRuns = [...mine.owned, ...mine.joined].filter((run) => run.archived === true);
  const owned = mine.owned.filter((run) => run.archived !== true);
  const joined = mine.joined.filter((run) => run.archived !== true);
  const archiveProps = (run: PersonalRun) => ({
    archiving: archivingId === run.id,
    onArchive: (target: PersonalRun, archived: boolean) => {
      void handleArchive(target, archived);
    },
  });

  // Story 16.22: owned and joined runs share the status groups, so a joined run in progress sits on
  // top with the owned ones instead of in a section of its own at the bottom.
  const joinedIds = new Set(mine.joined.map((run) => run.id));
  const grouped: Partial<Record<PersonalRunStatus, PersonalRun[]>> = {};
  const cancelledRuns: PersonalRun[] = [];

  for (const run of [...owned, ...joined]) {
    if (COLLAPSED_STATUSES.includes(run.status)) {
      cancelledRuns.push(run);
    } else {
      if (!grouped[run.status]) grouped[run.status] = [];
      grouped[run.status]!.push(run);
    }
  }

  const activeGroups = STATUS_ORDER.filter((s) => (grouped[s]?.length ?? 0) > 0);

  return (
    <div className={embedded ? "grid gap-8" : "mx-auto grid max-w-content gap-10"}>
      {embedded ? (
        <div className="flex items-center justify-between">
          <h2 className="font-heading text-xl font-bold text-foreground">Mes parties</h2>
          <button
            className="inline-flex items-center gap-2 rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            onClick={() => (formOpen ? closeForm() : setShowForm(true))}
            type="button"
          >
            <Plus aria-hidden className="size-4" />
            Créer une partie
          </button>
        </div>
      ) : (
        <section>
          <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Parties personnelles
          </p>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
                Mes parties.
              </h1>
              <p className="mt-3 text-lg leading-8 text-muted-foreground">
                Lance et gère tes sessions Archipelago privées.
              </p>
            </div>
            <button
              className="inline-flex items-center gap-2 rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
              onClick={() => (formOpen ? closeForm() : setShowForm(true))}
              type="button"
            >
              <Plus aria-hidden className="size-4" />
              Créer une partie
            </button>
          </div>
        </section>
      )}

      {/* Story 43.1: invitations by name, answered first. */}
      <MyRunInvitations />

      {formOpen && (
        <section className="rounded-lg border border-border bg-surface p-6">
          <h2 className="mb-4 font-heading text-lg font-semibold text-foreground">
            Nouvelle partie
          </h2>
          <form id={formId} onSubmit={(e) => void handleCreate(e)}>
            <div className="grid gap-3">
              <label className="grid gap-1.5" htmlFor={`${formId}-title`}>
                <span className="text-sm font-medium text-foreground">Titre</span>
                <input
                  className={[
                    "rounded border px-3 py-2 text-sm text-foreground bg-background transition-colors",
                    titleError ? "border-[color:var(--color-danger)]" : "border-border focus:border-accent",
                    "focus:outline-none",
                  ].join(" ")}
                  id={`${formId}-title`}
                  maxLength={80}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="Ma partie Archipelago"
                  type="text"
                  value={title}
                />
                {titleError && (
                  <p className="text-xs text-[color:var(--color-danger)]">{titleError}</p>
                )}
                <p className="text-xs text-muted-foreground">{title.length}/80 caractères</p>
              </label>
              {invitee !== null ? (
                <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                  <span>
                    <span className="font-medium text-foreground">{invitee.displayName ?? invitee.slug}</span> sera invité
                    dès la création de la partie.
                  </span>
                  <button
                    className="text-xs font-medium text-accent-text hover:underline"
                    onClick={() => replaceLocationParam(INVITE_PARAM, null)}
                    type="button"
                  >
                    Ne pas inviter
                  </button>
                </p>
              ) : null}
              <div className="flex justify-end gap-3">
                <button
                  className="rounded border border-border px-4 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                  onClick={closeForm}
                  type="button"
                >
                  Annuler
                </button>
                <button
                  className="inline-flex items-center gap-2 rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
                  disabled={creating}
                  type="submit"
                >
                  {creating && <Loader2 aria-hidden className="size-4 animate-spin" />}
                  Créer
                </button>
              </div>
            </div>
          </form>
        </section>
      )}

      {archiveError !== null ? (
        <p className="rounded border border-[color:var(--color-danger)]/40 px-4 py-2 text-sm text-[color:var(--color-danger)]" role="alert">
          {archiveError}
        </p>
      ) : null}

      {owned.length === 0 && joined.length === 0 && archivedRuns.length === 0 ? (
        <div className="rounded-lg border border-border bg-surface p-10 text-center">
          <Gamepad2 aria-hidden className="mx-auto mb-4 size-10 text-muted-foreground/50" />
          <p className="font-heading font-semibold text-foreground">
            Tu n&apos;as pas encore de partie personnelle.
          </p>
          <p className="mt-2 text-sm text-muted-foreground">
            Lance ta première partie Archipelago privée en cliquant sur{" "}
            <button
              className="text-accent-text hover:text-accent-text-hover"
              onClick={() => setShowForm(true)}
              type="button"
            >
              Créer une partie
            </button>
            .
          </p>
        </div>
      ) : (
        <div className="grid gap-8">
          {activeGroups.map((status) => (
            <section key={status}>
              <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                {GROUP_LABELS[status]}
              </h2>
              <div className="grid gap-3">
                {grouped[status]!.map((run) => (
                  <PersonalRunCard
                    joined={joinedIds.has(run.id)}
                    key={run.id}
                    restarting={restartingId === run.id}
                    run={run}
                    // Resuming stays the owner's action, as before joined runs shared these groups.
                    onRestart={status === "idle" && !joinedIds.has(run.id) ? (target) => { void handleRestart(target); } : undefined}
                    {...archiveProps(run)}
                  />
                ))}
              </div>
            </section>
          ))}

          {cancelledRuns.length > 0 && (
            <section>
              <button
                className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
                onClick={() => setShowCancelled((v) => !v)}
                type="button"
              >
                <span>Annulées ({cancelledRuns.length})</span>
                <span>{showCancelled ? "▲" : "▼"}</span>
              </button>
              {showCancelled && (
                <div className="grid gap-3 opacity-60">
                  {cancelledRuns.map((run) => (
                    <PersonalRunCard joined={joinedIds.has(run.id)} key={run.id} run={run} {...archiveProps(run)} />
                  ))}
                </div>
              )}
            </section>
          )}

          {archivedRuns.length > 0 && (
            <section>
              <button
                aria-expanded={showArchived}
                className="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
                onClick={() => setShowArchived((v) => !v)}
                type="button"
              >
                <Archive aria-hidden className="size-4" />
                <span>Archivées ({archivedRuns.length})</span>
                <span>{showArchived ? "▲" : "▼"}</span>
              </button>
              {showArchived && (
                <div className="grid gap-3 opacity-70">
                  {archivedRuns.map((run) => (
                    <PersonalRunCard joined={joinedIds.has(run.id)} key={run.id} run={run} {...archiveProps(run)} />
                  ))}
                </div>
              )}
            </section>
          )}
        </div>
      )}
    </div>
  );
}
