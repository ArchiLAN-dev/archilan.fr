"use client";

import Link from "next/link";
import { use, useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import {
  AlertTriangle,
  ArrowLeft,
  Check,
  Gamepad2,
  Loader2,
  PackageX,
  Play,
  RefreshCw,
  RotateCcw,
  Square,
  Trash2,
  X,
} from "lucide-react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useAuth } from "@/features/auth/auth-context";
import { PersonalRunStatusBadge } from "./personal-run-status-badge";
import { clearOverride, loadOverride, loadOverrideProfile, saveOverride } from "@/features/admin/admin-session-config-api";
import { SessionConfigOverrideForm } from "@/features/admin/session-config-override-form";
import { CollapsibleConfigPanel } from "@/components/collapsible-config-panel";
import { ConnectionDetails } from "./connection-details";
import { InviteLinkPanel } from "./invite-link-panel";
import { PlayerProgressGrid } from "@/components/session/PlayerProgressGrid";
import { PersonalRunPatchPanel } from "./personal-run-patches";
import type { PersonalRun, PersonalRunParticipant, ValidationSlotError } from "./types";

const POLLING_STATUSES = ["starting", "stopping", "restarting"] as const;

type PageState =
  | { kind: "loading" }
  | { kind: "not_found" }
  | { kind: "error"; message: string }
  | { kind: "ready"; run: PersonalRun };

// ─── My games card ────────────────────────────────────────────────────────────

function MyGamesCard({ run, mySlotCount }: { run: PersonalRun; mySlotCount: number }) {
  const canConfigure =
    run.status === "draft" ||
    run.status === "idle" ||
    !["starting", "active", "stopping", "restarting", "completed", "cancelled"].includes(run.status);

  return (
    <div className="rounded-lg border border-border bg-surface p-4">
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Gamepad2 aria-hidden className="size-4 text-accent-text" />
          <h3 className="text-sm font-semibold text-foreground">Mes jeux</h3>
          {mySlotCount > 0 && (
            <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
              {mySlotCount}
            </span>
          )}
        </div>
        {canConfigure && (
          <Link
            className="text-xs text-accent-text hover:text-accent-text-hover"
            href={`/runs/${run.id}/jeux`}
          >
            {mySlotCount > 0 ? "Modifier" : "Configurer"}
          </Link>
        )}
      </div>
      {mySlotCount === 0 ? (
        <p className="mt-2 text-xs text-muted-foreground">
          Tu n&apos;as pas encore sélectionné de jeux.{" "}
          {canConfigure && (
            <Link className="text-accent-text hover:text-accent-text-hover" href={`/runs/${run.id}/jeux`}>
              Configurer mes jeux →
            </Link>
          )}
        </p>
      ) : (
        <p className="mt-2 text-xs text-muted-foreground">
          {mySlotCount} jeu{mySlotCount > 1 ? "x" : ""} configuré{mySlotCount > 1 ? "s" : ""}.
        </p>
      )}
    </div>
  );
}

// ─── Participant list ─────────────────────────────────────────────────────────

function ParticipantList({ participants }: { participants: PersonalRunParticipant[] }) {
  if (participants.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">Aucun participant pour l&apos;instant.</p>
    );
  }

  return (
    <ul className="grid gap-2">
      {participants.map((p) => (
        <li className="flex items-center gap-3" key={p.userId}>
          <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent/20 text-xs font-semibold uppercase text-accent-text">
            {(p.displayName ?? p.userId).slice(0, 2)}
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-foreground">{p.displayName ?? p.userId}</p>
            <p className="text-xs text-muted-foreground">
              Depuis le{" "}
              {new Date(p.joinedAt).toLocaleDateString("fr-FR", {
                day: "numeric",
                month: "long",
                year: "numeric",
              })}
            </p>
          </div>
          {p.slotCount > 0 ? (
            <span className="shrink-0 rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
              {p.slotCount} jeu{p.slotCount > 1 ? "x" : ""}
            </span>
          ) : (
            <span className="shrink-0 text-xs text-muted-foreground/60">Sans jeux</span>
          )}
        </li>
      ))}
    </ul>
  );
}

// ─── Validation error banner ──────────────────────────────────────────────────

function ValidationErrorBanner({ errors }: { errors: ValidationSlotError[] }) {
  return (
    <div className="rounded-lg border border-[color:var(--color-danger)]/30 bg-[color:var(--color-danger)]/5 p-4">
      <div className="flex items-start gap-2">
        <AlertTriangle aria-hidden className="mt-0.5 size-4 shrink-0 text-[color:var(--color-danger)]" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-[color:var(--color-danger)]">
            La validation a échoué lors du dernier démarrage
          </p>
          <ul className="mt-2 grid gap-2">
            {errors.map((slot) => (
              <li key={slot.slotName}>
                <p className="text-xs font-medium text-foreground">Slot « {slot.slotName} »</p>
                <ul className="mt-0.5 list-disc pl-4">
                  {slot.errors.map((err) => (
                    <li className="text-xs text-muted-foreground" key={err}>{err}</li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}

// ─── Inactivity badge ─────────────────────────────────────────────────────────

function InactivityBadge({ lastActivityAt }: { lastActivityAt: string }) {
  const [now, setNow] = useState<number | null>(null);

  useEffect(() => {
    const updateNow = () => setNow(Date.now());
    updateNow();
    const interval = setInterval(updateNow, 60_000);

    return () => clearInterval(interval);
  }, [lastActivityAt]);

  if (now === null) {
    return null;
  }

  const delta = now - new Date(lastActivityAt).getTime();
  const totalMin = Math.floor(delta / 60_000);
  const hours = Math.floor(totalMin / 60);
  const minutes = totalMin % 60;
  const label = hours > 0 ? `Inactif depuis ${hours}h ${minutes}min` : `Inactif depuis ${minutes}min`;

  return (
    <p className="mt-1 text-sm text-muted-foreground">{label}</p>
  );
}

// ─── Stop confirmation dialog ─────────────────────────────────────────────────

function StopDialog({
  onConfirm,
  onCancel,
  stopping,
}: {
  onConfirm: () => void;
  onCancel: () => void;
  stopping: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="w-full max-w-sm rounded-lg border border-border bg-surface p-6 shadow-xl">
        <div className="mb-4 flex items-start gap-3">
          <AlertTriangle
            aria-hidden
            className="mt-0.5 size-5 shrink-0 text-[color:var(--color-accent-warm)]"
          />
          <div>
            <h2 className="font-heading font-semibold text-foreground">Arrêter la partie ?</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              Le serveur Archipelago sera arrêté. Tu pourras reprendre la partie plus tard.
            </p>
          </div>
        </div>
        <div className="flex justify-end gap-3">
          <button
            className="rounded border border-border px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground"
            onClick={onCancel}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex items-center gap-2 rounded bg-[color:var(--color-danger)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50"
            disabled={stopping}
            onClick={onConfirm}
            type="button"
          >
            {stopping && <Loader2 aria-hidden className="size-4 animate-spin" />}
            Arrêter
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Archive / delete confirmation dialogs ────────────────────────────────────

function ArchiveDialog({
  onConfirm,
  onCancel,
  archiving,
}: {
  onConfirm: () => void;
  onCancel: () => void;
  archiving: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="w-full max-w-sm rounded-lg border border-border bg-surface p-6 shadow-xl">
        <div className="mb-4 flex items-start gap-3">
          <Trash2 aria-hidden className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
          <div>
            <h2 className="font-heading font-semibold text-foreground">Archiver la partie ?</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              La partie sera archivée et n&apos;apparaîtra plus dans tes parties actives. Tu pourras la supprimer définitivement depuis l&apos;archive.
            </p>
          </div>
        </div>
        <div className="flex justify-end gap-3">
          <button
            className="rounded border border-border px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground"
            onClick={onCancel}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-semibold text-foreground hover:bg-surface disabled:opacity-50"
            disabled={archiving}
            onClick={onConfirm}
            type="button"
          >
            {archiving && <Loader2 aria-hidden className="size-4 animate-spin" />}
            Archiver
          </button>
        </div>
      </div>
    </div>
  );
}

function DeleteDialog({
  onConfirm,
  onCancel,
  deleting,
}: {
  onConfirm: () => void;
  onCancel: () => void;
  deleting: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="w-full max-w-sm rounded-lg border border-border bg-surface p-6 shadow-xl">
        <div className="mb-4 flex items-start gap-3">
          <Trash2 aria-hidden className="mt-0.5 size-5 shrink-0 text-[color:var(--color-danger)]" />
          <div>
            <h2 className="font-heading font-semibold text-foreground">Supprimer définitivement ?</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              Cette action est irréversible. La partie et toutes ses données seront supprimées de la base de données.
            </p>
          </div>
        </div>
        <div className="flex justify-end gap-3">
          <button
            className="rounded border border-border px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground"
            onClick={onCancel}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex items-center gap-2 rounded bg-[color:var(--color-danger)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50"
            disabled={deleting}
            onClick={onConfirm}
            type="button"
          >
            {deleting && <Loader2 aria-hidden className="size-4 animate-spin" />}
            Supprimer
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Main component ───────────────────────────────────────────────────────────

export function PersonalRunDetailPage({ params }: { params: Promise<{ runId: string }> }) {
  const { runId } = use(params);
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [state, setState] = useState<PageState>({ kind: "loading" });
  const [actionError, setActionError] = useState<string | null>(null);
  const [actioning, setActioning] = useState(false);
  const [showStopDialog, setShowStopDialog] = useState(false);
  const [showArchiveDialog, setShowArchiveDialog] = useState(false);
  const [archiving, setArchiving] = useState(false);
  const [unarchiving, setUnarchiving] = useState(false);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [successToast, setSuccessToast] = useState<string | null>(null);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const prevStatusRef = useRef<string | null>(null);
  const restartRequestedRef = useRef(false);

  const fetchRun = useCallback(async () => {
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}`);
      if (res.status === 404 || res.status === 403) {
        setState({ kind: "not_found" });
        return;
      }
      if (!res.ok) {
        setState({ kind: "error", message: "Une erreur est survenue lors du chargement." });
        return;
      }
      const payload = (await res.json()) as { data: PersonalRun };
      const newRun = payload.data;

      if ((prevStatusRef.current === "restarting" || restartRequestedRef.current) && newRun.status === "active") {
        restartRequestedRef.current = false;
        setSuccessToast("Partie reprise avec succès");
        setTimeout(() => { setSuccessToast(null); }, 4000);
      }
      prevStatusRef.current = newRun.status;

      setState({ kind: "ready", run: newRun });
    } catch {
      setState({ kind: "error", message: "Impossible de joindre le serveur." });
    }
  }, [runId]);

  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push(`/connexion?returnTo=/runs/${runId}`);
      return;
    }
    const timeout = setTimeout(() => {
      void fetchRun();
    }, 0);

    return () => clearTimeout(timeout);
  }, [authLoading, user, router, runId, fetchRun]);

  // Polling for transitional statuses
  useEffect(() => {
    if (state.kind !== "ready") return;

    const status = state.run.status;
    const shouldPoll = (POLLING_STATUSES as readonly string[]).includes(status);

    if (shouldPoll) {
      intervalRef.current = setInterval(() => { void fetchRun(); }, 3000);
    } else {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    }

    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current);
    };
  }, [state, fetchRun]);

  async function handleStart() {
    setActioning(true);
    setActionError(null);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/start`, { method: "POST" });
      if (!res.ok) {
        const payload = (await res.json()) as { error?: { code?: string; message?: string } };
        setActionError(payload.error?.message ?? "Impossible de démarrer la partie.");
        return;
      }
      await fetchRun();
    } catch {
      setActionError("Erreur réseau.");
    } finally {
      setActioning(false);
    }
  }

  async function handleStop() {
    setActioning(true);
    setActionError(null);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/stop`, { method: "POST" });
      setShowStopDialog(false);
      if (!res.ok) {
        const payload = (await res.json()) as { error?: { message?: string } };
        setActionError(payload.error?.message ?? "Impossible d'arrêter la partie.");
        return;
      }
      await fetchRun();
    } catch {
      setActionError("Erreur réseau.");
    } finally {
      setActioning(false);
    }
  }

  async function handleUnarchive() {
    setUnarchiving(true);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/unarchive`, { method: "POST" });
      if (!res.ok) {
        const payload = (await res.json()) as { error?: { message?: string } };
        setActionError(payload.error?.message ?? "Impossible de désarchiver la partie.");
        return;
      }
      await fetchRun();
    } catch {
      setActionError("Erreur réseau.");
    } finally {
      setUnarchiving(false);
    }
  }

  async function handleArchive() {
    setArchiving(true);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/archive`, { method: "POST" });
      setShowArchiveDialog(false);
      if (!res.ok) {
        const payload = (await res.json()) as { error?: { message?: string } };
        setActionError(payload.error?.message ?? "Impossible d'archiver la partie.");
        return;
      }
      router.push("/runs");
    } catch {
      setActionError("Erreur réseau.");
    } finally {
      setArchiving(false);
    }
  }

  async function handleDelete() {
    setDeleting(true);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}`, { method: "DELETE" });
      setShowDeleteDialog(false);
      if (!res.ok) {
        const payload = (await res.json()) as { error?: { message?: string } };
        setActionError(payload.error?.message ?? "Impossible de supprimer la partie.");
        return;
      }
      router.push("/runs");
    } catch {
      setActionError("Erreur réseau.");
    } finally {
      setDeleting(false);
    }
  }

  async function handleRestart(sessionId: string) {
    setActioning(true);
    setActionError(null);
    restartRequestedRef.current = true;
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/restart`, { method: "POST" });
      if (!res.ok) {
        restartRequestedRef.current = false;
        const payload = (await res.json()) as { error?: { code?: string; message?: string } };
        setActionError(payload.error?.message ?? "Impossible de reprendre la partie.");
        return;
      }
      await fetchRun();
    } catch {
      restartRequestedRef.current = false;
      setActionError("Erreur réseau.");
    } finally {
      setActioning(false);
    }
  }

  if (authLoading || state.kind === "loading") {
    return (
      <div className="mx-auto max-w-2xl">
        <div className="grid gap-4">
          <div className="h-12 animate-pulse rounded-lg border border-border bg-surface" />
          <div className="h-32 animate-pulse rounded-lg border border-border bg-surface" />
        </div>
      </div>
    );
  }

  if (state.kind === "not_found") {
    return (
      <div className="mx-auto max-w-sm py-20 text-center">
        <div className="mb-6 flex justify-center">
          <div className="flex size-16 items-center justify-center rounded-full border border-border bg-surface">
            <PackageX aria-hidden className="size-7 text-muted-foreground" />
          </div>
        </div>
        <h1 className="font-heading text-xl font-bold text-foreground">Partie introuvable</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Cette partie n&apos;existe pas ou tu n&apos;y as pas accès.
        </p>
        <div className="mt-8">
          <Link
            className="inline-flex items-center gap-2 rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/runs"
          >
            <ArrowLeft aria-hidden className="size-4" />
            Voir mes parties
          </Link>
        </div>
      </div>
    );
  }

  if (state.kind === "error") {
    return (
      <div className="mx-auto max-w-sm py-20 text-center">
        <div className="mb-6 flex justify-center">
          <div className="flex size-16 items-center justify-center rounded-full border border-[color:var(--color-danger)]/30 bg-[color:var(--color-danger)]/5">
            <AlertTriangle aria-hidden className="size-7 text-[color:var(--color-danger)]" />
          </div>
        </div>
        <h1 className="font-heading text-xl font-bold text-foreground">Erreur de chargement</h1>
        <p className="mt-2 text-sm text-muted-foreground">{state.message}</p>
        <div className="mt-8 flex flex-col items-center gap-3">
          <button
            className="inline-flex items-center gap-2 rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            onClick={() => { void fetchRun(); }}
            type="button"
          >
            <RefreshCw aria-hidden className="size-4" />
            Réessayer
          </button>
          <Link
            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            href="/runs"
          >
            <ArrowLeft aria-hidden className="size-3.5" />
            Retour à mes parties
          </Link>
        </div>
      </div>
    );
  }

  const run = state.run;
  const myUserId = user?.id ?? null;
  const myParticipant = run.participants.find((p) => p.userId === myUserId) ?? null;
  const mySlotCount = myParticipant?.slotCount ?? 0;
  const hasConfiguredGames = run.participants.some(p => p.slotCount > 0);
  const isStartable = (run.status === "draft" || run.status === "idle") && hasConfiguredGames;

  return (
    <>
      {showStopDialog && (
        <StopDialog
          onCancel={() => setShowStopDialog(false)}
          onConfirm={() => void handleStop()}
          stopping={actioning}
        />
      )}

      {showArchiveDialog && (
        <ArchiveDialog
          onCancel={() => setShowArchiveDialog(false)}
          onConfirm={() => void handleArchive()}
          archiving={archiving}
        />
      )}

      {showDeleteDialog && (
        <DeleteDialog
          onCancel={() => setShowDeleteDialog(false)}
          onConfirm={() => void handleDelete()}
          deleting={deleting}
        />
      )}

      {successToast !== null && (
        <div className="fixed bottom-6 right-6 z-50 flex items-center gap-2 rounded-lg border border-[color:var(--color-success)]/40 bg-[color:var(--color-success)]/10 px-4 py-3 text-sm font-medium text-[color:var(--color-success)] shadow-lg">
          <Check aria-hidden className="size-4 shrink-0" />
          {successToast}
        </div>
      )}

      <div className="mx-auto grid w-full max-w-2xl grid-cols-1 gap-8">
        {/* Header */}
        <header>
          <button
            className="mb-5 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            onClick={() => router.push("/runs")}
            type="button"
          >
            <ArrowLeft aria-hidden className="size-3.5" />
            Mes parties
          </button>

          <div className="flex flex-wrap items-start gap-3">
            <div className="flex-1">
              <h1 className="font-heading text-3xl font-bold leading-tight text-foreground">
                {run.title}
              </h1>
              {run.status === "idle" && run.lastActivityAt !== null && (
                <InactivityBadge lastActivityAt={run.lastActivityAt} />
              )}
            </div>
            <PersonalRunStatusBadge status={run.status} />
          </div>

          {run.isOwner && (
            <div className="mt-4">
              <InviteLinkPanel
                inviteToken={run.inviteToken}
                onTokenRegenerated={(newToken) => {
                  setState({
                    kind: "ready",
                    run: { ...run, inviteToken: newToken },
                  });
                }}
                runId={run.id}
              />
            </div>
          )}
        </header>

        {/* My games card - visible to owner + participants when configurable */}
        {(run.isOwner || myParticipant !== null) && (run.status === "draft" || run.status === "idle") && (
          <MyGamesCard mySlotCount={mySlotCount} run={run} />
        )}

        {run.isOwner && (
          <CollapsibleConfigPanel title="Configuration avancée (override)">
            <SessionConfigOverrideForm
              adapter={{
                queryKey: ["session-override", "private-run", run.id],
                load: () => loadOverride(`/runs/${run.id}/config-override`),
                loadProfile: () => loadOverrideProfile(`/runs/${run.id}/config-override`),
                save: (o) => saveOverride(`/runs/${run.id}/config-override`, o),
                clear: () => clearOverride(`/runs/${run.id}/config-override`),
              }}
              lockedKeys={["autoShutdown"]}
              scopeLabel="cette run"
            />
          </CollapsibleConfigPanel>
        )}

        {/* Status-conditional panels - owner actions */}
        {run.isOwner && (
          <section className="grid gap-4">
            {actionError && (
              <div className="flex items-center gap-2 rounded-lg border border-[color:var(--color-danger)]/30 bg-[color:var(--color-danger)]/5 px-4 py-3 text-sm text-[color:var(--color-danger)]">
                <X aria-hidden className="size-4 shrink-0" />
                {actionError}
              </div>
            )}

            {/* DRAFT */}
            {run.status === "draft" && (
              <>
                {run.validationErrors !== null && run.validationErrors.length > 0 && (
                  <ValidationErrorBanner errors={run.validationErrors} />
                )}
                <button
                  className="inline-flex w-full items-center justify-center gap-2 rounded bg-accent px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
                  disabled={!isStartable || actioning}
                  onClick={() => void handleStart()}
                  title={!hasConfiguredGames ? "Configure au moins un jeu pour pouvoir démarrer" : undefined}
                  type="button"
                >
                  {actioning ? (
                    <Loader2 aria-hidden className="size-4 animate-spin" />
                  ) : (
                    <Play aria-hidden className="size-4" />
                  )}
                  Démarrer la partie
                </button>
                {!hasConfiguredGames && (
                  <p className="text-center text-xs text-muted-foreground">
                    Configure au moins un jeu pour pouvoir démarrer.
                  </p>
                )}
                <div className="flex gap-2">
                  <button
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded border border-border px-4 py-2 text-sm font-semibold text-muted-foreground transition-colors hover:bg-surface"
                    onClick={() => setShowArchiveDialog(true)}
                    type="button"
                  >
                    <Trash2 aria-hidden className="size-4" />
                    Archiver
                  </button>
                  <button
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded border border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/5 px-4 py-2 text-sm font-semibold text-[color:var(--color-danger)] transition-colors hover:bg-[color:var(--color-danger)]/15"
                    onClick={() => setShowDeleteDialog(true)}
                    type="button"
                  >
                    <Trash2 aria-hidden className="size-4" />
                    Supprimer
                  </button>
                </div>
              </>
            )}

            {/* STARTING */}
            {run.status === "starting" && (
              <div className="grid gap-3">
                <div className="flex flex-col items-center gap-3 rounded-lg border border-[color:var(--color-accent-warm)]/30 bg-[color:var(--color-accent-warm)]/5 py-8">
                  <Loader2 aria-hidden className="size-8 animate-spin text-[color:var(--color-accent-warm)]" />
                  <p className="text-sm font-medium text-[color:var(--color-accent-warm)]">
                    Démarrage en cours…
                  </p>
                  <p className="text-xs text-muted-foreground">
                    La page se mettra à jour automatiquement.
                  </p>
                </div>
                <div className="flex gap-2">
                  <button
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded border border-border px-4 py-2 text-sm font-semibold text-muted-foreground transition-colors hover:bg-surface"
                    onClick={() => setShowArchiveDialog(true)}
                    type="button"
                  >
                    <Trash2 aria-hidden className="size-4" />
                    Archiver
                  </button>
                  <button
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded border border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/5 px-4 py-2 text-sm font-semibold text-[color:var(--color-danger)] transition-colors hover:bg-[color:var(--color-danger)]/15"
                    onClick={() => setShowDeleteDialog(true)}
                    type="button"
                  >
                    <Trash2 aria-hidden className="size-4" />
                    Supprimer
                  </button>
                </div>
              </div>
            )}

            {/* ACTIVE */}
            {run.status === "active" &&
              run.connectionHost !== null &&
              run.connectionPort !== null &&
              run.connectionPassword !== null && (
                <>
                  <ConnectionDetails
                    adminPassword={run.adminPassword}
                    host={run.connectionHost}
                    password={run.connectionPassword}
                    port={run.connectionPort}
                  />
                  <button
                    className="inline-flex w-full items-center justify-center gap-2 rounded border border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/10 px-4 py-3 text-sm font-semibold text-[color:var(--color-danger)] transition-colors hover:bg-[color:var(--color-danger)]/20"
                    onClick={() => setShowStopDialog(true)}
                    type="button"
                  >
                    <Square aria-hidden className="size-4" />
                    Arrêter la partie
                  </button>
                </>
              )}

            {/* STOPPING */}
            {run.status === "stopping" && (
              <div className="flex flex-col items-center gap-3 rounded-lg border border-[color:var(--color-accent-warm)]/30 bg-[color:var(--color-accent-warm)]/5 py-8">
                <Loader2 aria-hidden className="size-8 animate-spin text-[color:var(--color-accent-warm)]" />
                <p className="text-sm font-medium text-[color:var(--color-accent-warm)]">
                  Arrêt en cours…
                </p>
                <p className="text-xs text-muted-foreground">
                  La page se mettra à jour automatiquement.
                </p>
              </div>
            )}

            {/* IDLE */}
            {run.status === "idle" && (
              <div className="grid gap-3">
              <div className="rounded-lg border border-border bg-surface p-4">
                <p className="mb-3 rounded border border-border bg-background px-3 py-2 text-sm text-muted-foreground">
                  La partie s&apos;est mise en pause après une période d&apos;inactivité. Relance-la pour reprendre&nbsp;: la dernière sauvegarde sera chargée automatiquement.
                </p>
                {run.pausedWithoutSave ? (
                  <div>
                    <button
                      className="inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-semibold text-muted-foreground opacity-50 cursor-not-allowed"
                      disabled
                      title="Reprise impossible : aucune sauvegarde disponible"
                      type="button"
                    >
                      <RotateCcw aria-hidden className="size-4" />
                      Reprendre manuellement
                    </button>
                    <p className="mt-2 text-xs text-muted-foreground">
                      Reprise impossible : aucune sauvegarde disponible.
                    </p>
                  </div>
                ) : (
                  <button
                    className="inline-flex items-center gap-2 rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
                    disabled={actioning || !run.sessionId}
                    onClick={() => { if (run.sessionId) void handleRestart(run.sessionId); }}
                    type="button"
                  >
                    {actioning ? (
                      <Loader2 aria-hidden className="size-4 animate-spin" />
                    ) : (
                      <RotateCcw aria-hidden className="size-4" />
                    )}
                    Reprendre manuellement
                  </button>
                )}
              </div>
              <button
                className="inline-flex w-full items-center justify-center gap-2 rounded border border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/5 px-4 py-2 text-sm font-semibold text-[color:var(--color-danger)] transition-colors hover:bg-[color:var(--color-danger)]/15"
                onClick={() => setShowDeleteDialog(true)}
                type="button"
              >
                <Trash2 aria-hidden className="size-4" />
                Supprimer la partie
              </button>
              </div>
            )}

            {/* RESTARTING */}
            {run.status === "restarting" && (
              <div className="flex flex-col items-center gap-3 rounded-lg border border-[color:var(--color-accent-warm)]/30 bg-[color:var(--color-accent-warm)]/5 py-8">
                <Loader2 aria-hidden className="size-8 animate-spin text-[color:var(--color-accent-warm)]" />
                <p className="text-sm font-medium text-[color:var(--color-accent-warm)]">
                  Redémarrage en cours…
                </p>
                <p className="text-xs text-muted-foreground">
                  La page se mettra à jour automatiquement.
                </p>
              </div>
            )}

            {/* COMPLETED */}
            {run.status === "completed" && (
              <div className="rounded-lg border border-border bg-surface p-4 text-center">
                <p className="text-sm text-muted-foreground">Cette partie est terminée.</p>
              </div>
            )}

            {/* CANCELLED */}
            {run.status === "cancelled" && (
              <div className="grid gap-3">
                <div className="rounded-lg border border-border bg-surface p-4 text-center">
                  <p className="text-sm text-muted-foreground">Cette partie est archivée.</p>
                </div>
                <button
                  className="inline-flex w-full items-center justify-center gap-2 rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
                  disabled={unarchiving}
                  onClick={() => void handleUnarchive()}
                  type="button"
                >
                  {unarchiving ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <RotateCcw aria-hidden className="size-4" />}
                  Désarchiver
                </button>
                <button
                  className="inline-flex w-full items-center justify-center gap-2 rounded border border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/5 px-4 py-2 text-sm font-semibold text-[color:var(--color-danger)] transition-colors hover:bg-[color:var(--color-danger)]/15"
                  onClick={() => setShowDeleteDialog(true)}
                  type="button"
                >
                  <Trash2 aria-hidden className="size-4" />
                  Supprimer définitivement
                </button>
              </div>
            )}
          </section>
        )}

        {/* Non-owner: show status message when not configurable */}
        {!run.isOwner && !["draft", "idle"].includes(run.status) && (
          <section className="rounded-lg border border-border bg-surface p-4">
            <p className="text-sm text-muted-foreground">
              {run.status === "starting" && "La partie est en cours de démarrage…"}
              {run.status === "active" && "La partie est en cours."}
              {run.status === "stopping" && "La partie est en cours d'arrêt…"}
              {run.status === "restarting" && "La partie redémarre…"}
              {run.status === "completed" && "La partie est terminée."}
              {run.status === "cancelled" && "La partie a été annulée."}
            </p>
          </section>
        )}

        {/* Active: connection info for non-owner participants */}
        {!run.isOwner &&
          run.status === "active" &&
          run.connectionHost !== null &&
          run.connectionPort !== null &&
          run.connectionPassword !== null && (
            <ConnectionDetails
              host={run.connectionHost}
              password={run.connectionPassword}
              port={run.connectionPort}
            />
          )}

        {/* Generated patch download — each participant gets their own slot's patch */}
        <PersonalRunPatchPanel enabled={run.status === "active"} runId={run.id} />

        {/* Player progress grid - visible to all when active or idle */}
        {run.sessionId && (run.status === "active" || run.status === "idle") && (
          <PlayerProgressGrid personalRunId={run.id} runId={run.sessionId} />
        )}

        {/* Participants section */}
        <section className="rounded-lg border border-border bg-surface p-4">
          <h2 className="mb-3 text-sm font-semibold text-foreground">
            Participants
            {run.participants.length > 0 && (
              <span className="ml-2 rounded-full border border-border px-2 py-0.5 text-xs font-normal text-muted-foreground">
                {run.participants.length}
              </span>
            )}
          </h2>
          <ParticipantList participants={run.participants} />
        </section>
      </div>
    </>
  );
}
