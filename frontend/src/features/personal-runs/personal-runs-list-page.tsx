"use client";

import { useCallback, useEffect, useId, useState } from "react";
import type { FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Gamepad2, Loader2, Plus } from "lucide-react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useAuth } from "@/features/auth/auth-context";
import { PersonalRunCard } from "./personal-run-card";
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

const GROUP_LABELS: Partial<Record<PersonalRunStatus, string>> = {
  active: "En cours",
  starting: "En démarrage",
  stopping: "En arrêt",
  idle: "En pause",
  restarting: "Redémarrage…",
  draft: "Brouillons",
  completed: "Terminées",
};

type MineData = { owned: PersonalRun[]; joined: PersonalRun[] };

function isMineData(value: unknown): value is MineData {
  if (typeof value !== "object" || value === null) return false;
  const data = value as Record<string, unknown>;
  return Array.isArray(data.owned) && Array.isArray(data.joined);
}

type PageState =
  | { kind: "loading" }
  | { kind: "error" }
  | { kind: "ready"; owned: PersonalRun[]; joined: PersonalRun[] };

export function PersonalRunsListPage({ embedded = false }: { embedded?: boolean }) {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [state, setState] = useState<PageState>({ kind: "loading" });
  const [showForm, setShowForm] = useState(false);
  const [title, setTitle] = useState("");
  const [titleError, setTitleError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [showCancelled, setShowCancelled] = useState(false);
  const [restartingId, setRestartingId] = useState<string | null>(null);
  const formId = useId();

  const loadRuns = useCallback(async () => {
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/mine`);
      if (!res.ok) { setState({ kind: "error" }); return; }
      const payload = (await res.json()) as { data?: unknown };
      if (!isMineData(payload.data)) { setState({ kind: "error" }); return; }
      setState({ kind: "ready", owned: payload.data.owned, joined: payload.data.joined });
    } catch {
      setState({ kind: "error" });
    }
  }, []);

  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push("/connexion?returnTo=/runs");
      return;
    }

    const timeout = setTimeout(() => {
      void loadRuns();
    }, 0);

    return () => clearTimeout(timeout);
  }, [authLoading, user, router, loadRuns]);

  async function handleRestart(run: PersonalRun) {
    if (run.sessionId === null || run.pausedWithoutSave) return;

    setRestartingId(run.id);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/sessions/${run.sessionId}/restart`, { method: "POST" });
      if (res.ok) {
        await loadRuns();
      }
    } finally {
      setRestartingId(null);
    }
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
      router.push(`/runs/${payload.data.id}`);
    } catch {
      setTitleError("Erreur réseau.");
    } finally {
      setCreating(false);
    }
  }

  if (authLoading || state.kind === "loading") {
    return (
      <div className={embedded ? undefined : "mx-auto max-w-3xl"}>
        <div className="grid gap-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-20 animate-pulse rounded-lg border border-border bg-surface" />
          ))}
        </div>
      </div>
    );
  }

  if (state.kind === "error") {
    return (
      <div className={embedded ? undefined : "mx-auto max-w-3xl"}>
        <p className="text-sm text-muted-foreground">
          Impossible de charger tes parties pour le moment.
        </p>
      </div>
    );
  }

  const { owned, joined } = state;

  // Group owned runs by status
  const grouped: Partial<Record<PersonalRunStatus, PersonalRun[]>> = {};
  const cancelledRuns: PersonalRun[] = [];

  for (const run of owned) {
    if (COLLAPSED_STATUSES.includes(run.status)) {
      cancelledRuns.push(run);
    } else {
      if (!grouped[run.status]) grouped[run.status] = [];
      grouped[run.status]!.push(run);
    }
  }

  const activeGroups = STATUS_ORDER.filter((s) => (grouped[s]?.length ?? 0) > 0);

  return (
    <div className={embedded ? "grid gap-8" : "mx-auto grid max-w-3xl gap-10"}>
      {embedded ? (
        <div className="flex items-center justify-between">
          <h2 className="font-heading text-xl font-bold text-foreground">Mes parties</h2>
          <button
            className="inline-flex items-center gap-2 rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            onClick={() => setShowForm((v) => !v)}
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
              onClick={() => setShowForm((v) => !v)}
              type="button"
            >
              <Plus aria-hidden className="size-4" />
              Créer une partie
            </button>
          </div>
        </section>
      )}

      {showForm && (
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
              <div className="flex justify-end gap-3">
                <button
                  className="rounded border border-border px-4 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                  onClick={() => { setShowForm(false); setTitle(""); setTitleError(null); }}
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

      {owned.length === 0 && joined.length === 0 ? (
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
                    key={run.id}
                    restarting={restartingId === run.id}
                    run={run}
                    onRestart={status === "idle" ? (target) => { void handleRestart(target); } : undefined}
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
                    <PersonalRunCard key={run.id} run={run} />
                  ))}
                </div>
              )}
            </section>
          )}

          {joined.length > 0 && (
            <section>
              <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                Parties rejointes
              </h2>
              <div className="grid gap-3">
                {joined.map((run) => (
                  <PersonalRunCard key={run.id} run={run} />
                ))}
              </div>
            </section>
          )}
        </div>
      )}
    </div>
  );
}
