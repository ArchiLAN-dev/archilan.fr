"use client";

import Link from "next/link";
import { use, useEffect, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  AlertTriangle,
  ArrowLeft,
  Check,
  Eye,
  Flag,
  Gamepad2,
  Loader2,
  PackageX,
  Play,
  RefreshCw,
  RotateCcw,
  ShieldCheck,
  Square,
  Trash2,
  X,
} from "lucide-react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { REALTIME_STALE_TIME } from "@/lib/query-client";
import { useAuth } from "@/features/auth/auth-context";
import { fetchPersonalRun, setRunRecapVisibility, type PersonalRunResult } from "./personal-runs-api";
import { IdleBanner } from "./idle-banner";
import { ImportedSeedPanel } from "./imported-seed-panel";
import { PersonalRunStatusBadge } from "./personal-run-status-badge";
import { clearOverride, loadOverride, loadOverrideProfile, saveOverride } from "@/features/admin/admin-session-config-api";
import { SessionConfigOverrideForm } from "@/features/admin/session-config-override-form";
import { ConnectionDetails } from "./connection-details";
import { InviteLinkPanel } from "./invite-link-panel";
import { PlayerProgressGrid } from "@/components/session/PlayerProgressGrid";
import { LiveRunTimeline } from "@/features/recap/live-run-timeline";
import { OverlayLinksPanel } from "@/features/overlay/overlay-links-panel";
import { PersonalRunPatchPanel } from "./personal-run-patches";
import { PersonalRunSpoilerPanel } from "./personal-run-spoiler";
import { ParticipantStreams } from "@/features/streaming/participant-streams";
import { PlayerBadges } from "@/features/community/player-badges";
import { RunTitle } from "./run-title";
import { showsRunStatusLine } from "./run-overview-visibility";
import type { PersonalRun, PersonalRunParticipant, ValidationSlotError } from "./types";

const POLLING_STATUSES = ["starting", "stopping", "restarting"] as const;

type PageState =
  | { kind: "loading" }
  | { kind: "not_found" }
  | { kind: "error"; message: string }
  | { kind: "ready"; run: PersonalRun };

// ─── My games card ────────────────────────────────────────────────────────────

function MyGamesCard({ run, mySlotCount }: { run: PersonalRun; mySlotCount: number }) {
  // Editing games/YAML is only meaningful before the first generation: once the run leaves draft the
  // multiworld is fixed (resume replays the existing session), so the edit entry is hidden.
  const canConfigure = run.status === "draft";

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

function ParticipantAvatar({ avatarUrl, name }: { avatarUrl: string | null; name: string }) {
  const [failed, setFailed] = useState(false);

  if (avatarUrl !== null && !failed) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- external/presigned avatar URL, not a local asset
      <img
        alt=""
        aria-hidden="true"
        className="size-8 shrink-0 rounded-full bg-surface object-cover"
        onError={() => setFailed(true)}
        src={avatarUrl}
      />
    );
  }

  return (
    <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent/20 text-xs font-semibold uppercase text-accent-text">
      {name.slice(0, 2)}
    </div>
  );
}

function ParticipantList({ runId, participants }: { runId: string; participants: PersonalRunParticipant[] }) {
  if (participants.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">Aucun participant pour l&apos;instant.</p>
    );
  }

  return (
    <ul className="grid gap-2">
      {participants.map((p) => {
        const name = p.displayName ?? p.userId;

        return (
          <li className="flex items-center gap-3" key={p.userId}>
            <ParticipantAvatar avatarUrl={p.avatarUrl} name={name} />
            <div className="min-w-0 flex-1">
              {p.slug !== null ? (
                <Link
                  className="block truncate text-sm font-medium text-foreground transition-colors hover:text-accent-text hover:underline"
                  href={`/joueurs/${p.slug}`}
                >
                  {name}
                </Link>
              ) : (
                <p className="truncate text-sm font-medium text-foreground">{name}</p>
              )}
              <PlayerBadges
                className="mt-1"
                isAdmin={p.isAdmin}
                isMember={p.isMember}
                level={p.level}
                playing={p.playing}
              />
              <p className="mt-1 text-xs text-muted-foreground">
                Depuis le{" "}
                {new Date(p.joinedAt).toLocaleDateString("fr-FR", {
                  day: "numeric",
                  month: "long",
                  year: "numeric",
                })}
              </p>
            </div>
            {p.slotCount > 0 ? (
              <Link
                className="shrink-0 inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:border-accent hover:text-accent-text"
                href={`/runs/${runId}/participants/${p.userId}`}
                title={`Voir les jeux et la configuration de ${name}`}
              >
                <Eye aria-hidden className="size-3" />
                {p.slotCount} jeu{p.slotCount > 1 ? "x" : ""}
              </Link>
            ) : (
              <span className="shrink-0 text-xs text-muted-foreground/60">Sans jeux</span>
            )}
          </li>
        );
      })}
    </ul>
  );
}

// ─── Validation error banner ──────────────────────────────────────────────────

function ValidationErrorBanner({
  errors,
  logExcerpt = null,
}: {
  errors: ValidationSlotError[];
  logExcerpt?: string | null;
}) {
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
          {logExcerpt !== null && logExcerpt !== "" ? (
            <details className="mt-3">
              <summary className="cursor-pointer text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                Détails techniques
              </summary>
              <pre className="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-md border border-border bg-surface p-3 text-[11px] leading-relaxed text-muted-foreground">
                {logExcerpt}
              </pre>
            </details>
          ) : null}
        </div>
      </div>
    </div>
  );
}

// ─── Inactivity badge ─────────────────────────────────────────────────────────

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

function FinishDialog({
  onConfirm,
  onCancel,
  finishing,
}: {
  onConfirm: () => void;
  onCancel: () => void;
  finishing: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="w-full max-w-sm rounded-lg border border-border bg-surface p-6 shadow-xl">
        <div className="mb-4 flex items-start gap-3">
          <Flag aria-hidden className="mt-0.5 size-5 shrink-0 text-[color:var(--color-accent)]" />
          <div>
            <h2 className="font-heading font-semibold text-foreground">Terminer la partie ?</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              La partie sera clôturée définitivement et archivée (tu pourras consulter ses résultats). La
              progression réelle est enregistrée à ce moment : une partie n&apos;est comptée dans tes stats
              que si tu as atteint ton objectif.
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
            className="inline-flex items-center gap-2 rounded bg-[color:var(--color-accent)] px-4 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-accent-hover)] disabled:opacity-50"
            disabled={finishing}
            onClick={onConfirm}
            type="button"
          >
            {finishing && <Loader2 aria-hidden className="size-4 animate-spin" />}
            Terminer
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

type RunTab = "overview" | "progress" | "participants" | "streams" | "overlay" | "settings";

const RUN_TABS: readonly RunTab[] = ["overview", "progress", "participants", "streams", "overlay", "settings"];

function isRunTab(value: string | null): value is RunTab {
  return value !== null && (RUN_TABS as readonly string[]).includes(value);
}

export function PersonalRunDetailPage({ params }: { params: Promise<{ runId: string }> }) {
  const { runId } = use(params);
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();
  const [actionError, setActionError] = useState<string | null>(null);
  const [actioning, setActioning] = useState(false);
  const [showStopDialog, setShowStopDialog] = useState(false);
  const [showFinishDialog, setShowFinishDialog] = useState(false);
  const [showArchiveDialog, setShowArchiveDialog] = useState(false);
  const [archiving, setArchiving] = useState(false);
  const [unarchiving, setUnarchiving] = useState(false);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [successToast, setSuccessToast] = useState<string | null>(null);
  // Active tab lives in the URL (?tab=) so a reload (or a shared link) keeps the same tab open.
  const tabParam = searchParams.get("tab");
  const tab: RunTab = isRunTab(tabParam) ? tabParam : "overview";
  const prevStatusRef = useRef<string | null>(null);
  const restartRequestedRef = useRef(false);
  // The tab bar scrolls horizontally on a phone (story 16.12); keep the active tab in view when
  // it changes (or when the page lands on a deep-linked tab past the scrollable edge).
  const tabListRef = useRef<HTMLDivElement | null>(null);
  useEffect(() => {
    const active = tabListRef.current?.querySelector<HTMLElement>('[aria-selected="true"]');
    active?.scrollIntoView({ block: "nearest", inline: "nearest" });
  }, [tab]);

  // fetchPersonalRun never throws (404/403, server errors and network failures are encoded in the
  // result's `kind`), so the query never errors and - like the old effect - never retries.
  // Adaptive polling: fast (3s) during transitional states; slow (30s) while active so a container
  // that stops itself (idle / auto_shutdown) is reflected without a manual refresh; off otherwise.
  const runQuery = useQuery({
    queryKey: ["personal-run", runId],
    queryFn: () => fetchPersonalRun(runId),
    enabled: !authLoading && user !== null,
    staleTime: REALTIME_STALE_TIME,
    retry: false,
    refetchInterval: (query) => {
      const result = query.state.data;
      if (result?.kind !== "ready") return false;
      if ((POLLING_STATUSES as readonly string[]).includes(result.run.status)) return 3_000;
      return result.run.status === "active" ? 30_000 : false;
    },
  });
  const runResult = runQuery.data;

  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push(`/connexion?returnTo=/runs/${runId}`);
    }
  }, [authLoading, user, router, runId]);

  // Restart-success toast, derived from the status TRANSITION between two query results
  // (restarting -> active, or an explicit restart request resolving to active).
  useEffect(() => {
    if (runResult?.kind !== "ready") return;
    const status = runResult.run.status;
    if ((prevStatusRef.current === "restarting" || restartRequestedRef.current) && status === "active") {
      restartRequestedRef.current = false;
      setSuccessToast("Partie reprise avec succès");
      setTimeout(() => { setSuccessToast(null); }, 4000);
    }
    prevStatusRef.current = status;
  }, [runResult]);

  async function refreshRun() {
    await queryClient.invalidateQueries({ queryKey: ["personal-run", runId] });
    // The list page groups runs by status: mark its cache stale too so navigating back reflects the
    // change immediately (the old page refetched the list on every mount).
    void queryClient.invalidateQueries({ queryKey: ["personal-runs", "mine"] });
  }

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
      await refreshRun();
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
      await refreshRun();
    } catch {
      setActionError("Erreur réseau.");
    } finally {
      setActioning(false);
    }
  }

  async function handleFinish() {
    setActioning(true);
    setActionError(null);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/finish`, { method: "POST" });
      setShowFinishDialog(false);
      if (!res.ok) {
        const payload = (await res.json()) as { error?: { message?: string } };
        setActionError(payload.error?.message ?? "Impossible de terminer la partie.");
        return;
      }
      await refreshRun();
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
      await refreshRun();
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
      void queryClient.invalidateQueries({ queryKey: ["personal-runs", "mine"] });
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
      void queryClient.invalidateQueries({ queryKey: ["personal-runs", "mine"] });
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
      await refreshRun();
    } catch {
      restartRequestedRef.current = false;
      setActionError("Erreur réseau.");
    } finally {
      setActioning(false);
    }
  }

  // Garde-fou (story 17.14) : force la résolution d'une partie coincée dans une transition
  // (redémarrage / démarrage bloqué). Le serveur la ramène à un état stable (idle / draft) ou la
  // confirme active si elle tourne réellement, afin que le proprio ne reste jamais bloqué "en attente".
  async function handleForceReconcile(sessionId: string) {
    setActioning(true);
    setActionError(null);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/reconcile`, { method: "POST" });
      if (!res.ok) {
        const payload = (await res.json()) as { error?: { message?: string } };
        setActionError(payload.error?.message ?? "Impossible de débloquer la partie.");
        return;
      }
      await refreshRun();
    } catch {
      setActionError("Erreur réseau.");
    } finally {
      setActioning(false);
    }
  }

  // Same state machine as before the TanStack conversion: loading while auth or the query resolves,
  // then the query result maps 1:1 onto the not_found / error / ready kinds.
  const state: PageState =
    authLoading || runQuery.isPending
      ? { kind: "loading" }
      : runResult ?? { kind: "error", message: "Impossible de joindre le serveur." };

  if (state.kind === "loading") {
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
            onClick={() => { void runQuery.refetch(); }}
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
  const isAdmin = user?.roles.includes("ROLE_ADMIN") ?? false;
  const sessionLive = run.sessionId !== null && (run.status === "active" || run.status === "idle");

  // Tabs adapt to role + lifecycle; "Vue d'ensemble" and "Participants" are always present.
  const tabs: { key: RunTab; label: string }[] = [
    { key: "overview", label: "Vue d'ensemble" },
    ...(sessionLive ? [{ key: "progress" as const, label: "Progression" }] : []),
    { key: "participants", label: "Participants" },
    { key: "streams", label: "Streams" },
    ...(sessionLive && (run.isOwner || isAdmin) ? [{ key: "overlay" as const, label: "Overlay Stream" }] : []),
    ...(run.isOwner || isAdmin ? [{ key: "settings" as const, label: "Réglages" }] : []),
  ];
  const activeTab: RunTab = tabs.some((t) => t.key === tab) ? tab : "overview";

  function selectTab(next: RunTab): void {
    const params = new URLSearchParams(searchParams.toString());
    params.set("tab", next);
    router.replace(`${pathname}?${params.toString()}`, { scroll: false });
  }

  return (
    <>
      {showFinishDialog && (
        <FinishDialog
          finishing={actioning}
          onCancel={() => setShowFinishDialog(false)}
          onConfirm={() => void handleFinish()}
        />
      )}

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
              <RunTitle canRename={run.isOwner} onRenamed={refreshRun} runId={run.id} title={run.title} />
            </div>
            <PersonalRunStatusBadge status={run.status} />
          </div>

          {run.isOwner && run.inviteToken !== null && (
            <div className="mt-4">
              <InviteLinkPanel
                inviteToken={run.inviteToken}
                onTokenRegenerated={(newToken) => {
                  // Write the regenerated token straight into the cache (same in-place update as the
                  // old setState) - no refetch needed.
                  queryClient.setQueryData<PersonalRunResult>(
                    ["personal-run", runId],
                    (prev) =>
                      prev?.kind === "ready"
                        ? { kind: "ready", run: { ...prev.run, inviteToken: newToken } }
                        : prev,
                  );
                }}
                runId={run.id}
              />
            </div>
          )}
        </header>

        {/* Reprise d'une run en veille, ouverte à tout participant (story 16.14) : un propriétaire
            absent bloquait la partie de tout le monde. Rendue avant les onglets (story 16.15) parce
            qu'une run en veille n'a qu'une action utile, et que l'onglet ouvert ne devrait pas la
            cacher. `canStart` n'est vrai pour un participant que sur une run en veille, jamais sur
            un premier lancement. */}
        {run.status === "idle" && run.canStart && (
          <IdleBanner
            busy={actioning || !run.sessionId}
            lastActivityAt={run.lastActivityAt}
            onResume={() => { if (run.sessionId) void handleRestart(run.sessionId); }}
            pausedWithoutSave={run.pausedWithoutSave}
          />
        )}

        {/* Tabs: one row on every width - horizontal scroll on a phone (never a wrapped block),
            with a right-edge fade hinting at the overflow (story 16.12). */}
        <div className="relative">
          <div
            className="flex gap-1 overflow-x-auto border-b border-border [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            ref={tabListRef}
            role="tablist"
          >
            {tabs.map((t) => {
              const active = t.key === activeTab;
              return (
                <button
                  aria-selected={active}
                  className={`-mb-px shrink-0 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-semibold transition-colors ${
                    active
                      ? "border-accent text-foreground"
                      : "border-transparent text-muted-foreground hover:text-foreground"
                  }`}
                  key={t.key}
                  onClick={() => {
                    selectTab(t.key);
                  }}
                  role="tab"
                  type="button"
                >
                  {t.label}
                </button>
              );
            })}
          </div>
          <div aria-hidden className="pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-background to-transparent sm:hidden" />
        </div>

        {/* My games card - visible to owner + participants when configurable */}
        {activeTab === "overview" && (run.isOwner || myParticipant !== null) && (run.status === "draft" || run.status === "idle") && (
          <MyGamesCard mySlotCount={mySlotCount} run={run} />
        )}

        {/* Story 16.19 : une interface identique à celle du propriétaire invite à oublier de qui est
            la partie qu'on modifie. */}
        {activeTab === "settings" && !run.isOwner && isAdmin && (
          <section className="mb-4 flex items-start gap-2 rounded-lg border border-accent-warm/40 bg-accent-warm/5 p-4 text-sm">
            <ShieldCheck aria-hidden className="mt-0.5 size-4 shrink-0 text-accent-warm" />
            <p className="text-muted-foreground">
              <span className="font-semibold text-foreground">Tu interviens en administrateur.</span>{" "}
              Cette partie appartient à un autre membre, et chaque réglage que tu appliques ici est
              consigné dans sa fiche.
            </p>
          </section>
        )}

        {activeTab === "settings" && (run.isOwner || isAdmin) && (
          <section className="mb-4">
            <ImportedSeedPanel
              editable={run.status === "draft"}
              importedSeed={run.importedSeed === true}
              importedSlots={run.importedSlots ?? []}
              onChanged={() => runQuery.refetch()}
              participants={run.participants}
              runId={run.id}
            />
          </section>
        )}

        {activeTab === "settings" && (run.isOwner || isAdmin) && (
          <section className="rounded-lg border border-border bg-surface p-4">
            <h2 className="mb-3 text-sm font-semibold text-foreground">Configuration avancée (override)</h2>
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
          </section>
        )}

        {/* La suppression d'une run en veille vivait sous le bandeau de reprise, en pleine largeur,
            donc plus lourde à l'œil que la seule action utile de cet état (story 16.15). Elle reste
            réservée au propriétaire : l'onglet Réglages n'existe que pour lui. */}
        {activeTab === "settings" && (run.isOwner || isAdmin) && run.status === "idle" && (
          <section className="rounded-lg border border-[color:var(--color-danger)]/30 bg-surface p-4">
            <h2 className="mb-1 text-sm font-semibold text-foreground">Supprimer la partie</h2>
            <p className="mb-3 text-sm text-muted-foreground">
              La partie et toutes ses données sont retirées définitivement. Les fichiers déjà téléchargés ne sont pas
              affectés.
            </p>
            <button
              className="inline-flex items-center justify-center gap-2 rounded border border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/5 px-4 py-2 text-sm font-semibold text-[color:var(--color-danger)] transition-colors hover:bg-[color:var(--color-danger)]/15"
              onClick={() => setShowDeleteDialog(true)}
              type="button"
            >
              <Trash2 aria-hidden className="size-4" />
              Supprimer la partie
            </button>
          </section>
        )}

        {/* Erreur d'action - commune au propriétaire et au participant qui reprend la run. */}
        {activeTab === "overview" && actionError && (
          <div className="flex items-center gap-2 rounded-lg border border-[color:var(--color-danger)]/30 bg-[color:var(--color-danger)]/5 px-4 py-3 text-sm text-[color:var(--color-danger)]">
            <X aria-hidden className="size-4 shrink-0" />
            {actionError}
          </div>
        )}

        {/* Status-conditional panels - owner actions */}
        {activeTab === "overview" && run.isOwner && (
          <section className="grid min-w-0 grid-cols-1 gap-4">
            {/* DRAFT */}
            {run.status === "draft" && (
              <>
                {run.validationErrors !== null && run.validationErrors.length > 0 && (
                  <ValidationErrorBanner errors={run.validationErrors} logExcerpt={run.generationLogExcerpt ?? null} />
                )}
                {run.status === "draft" && (run.failedPreflightCount ?? 0) > 0 && (
                  <p className="rounded-lg border border-[color:var(--color-warning)]/30 bg-[color:var(--color-warning)]/5 p-3 text-xs text-[color:var(--color-warning)]">
                    {run.failedPreflightCount} slot{(run.failedPreflightCount ?? 0) > 1 ? "s ont" : " a"} échoué au
                    test de génération individuel. La génération complète risque d&apos;échouer : vérifie les configs
                    marquées « Échec du test » avant de lancer.
                  </p>
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
                  {run.sessionId !== null && (
                    <button
                      className="mt-2 inline-flex items-center gap-2 rounded border border-border bg-background px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
                      disabled={actioning}
                      onClick={() => { if (run.sessionId) void handleForceReconcile(run.sessionId); }}
                      type="button"
                    >
                      {actioning ? <Loader2 aria-hidden className="size-3.5 animate-spin" /> : <RotateCcw aria-hidden className="size-3.5" />}
                      Bloqué ? Forcer la résolution
                    </button>
                  )}
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
            {/* Story 16.13: the password is no longer part of the condition - a run launched
                without one must still show its host and port, not vanish entirely. */}
            {run.status === "active" &&
              run.connectionHost !== null &&
              run.connectionPort !== null && (
                <>
                  <ConnectionDetails
                    adminPassword={run.adminPassword}
                    host={run.connectionHost}
                    password={run.connectionPassword}
                    port={run.connectionPort}
                    uri={run.connectionUri ?? null}
                  />
                  <div className="grid gap-2 sm:grid-cols-2">
                    <button
                      className="inline-flex w-full items-center justify-center gap-2 rounded border border-[color:var(--color-accent)]/40 bg-[color:var(--color-accent)]/10 px-4 py-3 text-sm font-semibold text-[color:var(--color-accent-text)] transition-colors hover:bg-[color:var(--color-accent)]/20"
                      onClick={() => setShowFinishDialog(true)}
                      type="button"
                    >
                      <Flag aria-hidden className="size-4" />
                      Terminer la partie
                    </button>
                    <button
                      className="inline-flex w-full items-center justify-center gap-2 rounded border border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/10 px-4 py-3 text-sm font-semibold text-[color:var(--color-danger)] transition-colors hover:bg-[color:var(--color-danger)]/20"
                      onClick={() => setShowStopDialog(true)}
                      type="button"
                    >
                      <Square aria-hidden className="size-4" />
                      Arrêter la partie
                    </button>
                  </div>
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

            {/* IDLE - le bandeau de reprise est rendu au-dessus des onglets et la suppression a
                rejoint l'onglet Réglages (story 16.15). Il ne reste rien ici. */}

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
                {run.sessionId !== null && (
                  <button
                    className="mt-2 inline-flex items-center gap-2 rounded border border-border bg-background px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
                    disabled={actioning}
                    onClick={() => { if (run.sessionId) void handleForceReconcile(run.sessionId); }}
                    type="button"
                  >
                    {actioning ? <Loader2 aria-hidden className="size-3.5 animate-spin" /> : <RotateCcw aria-hidden className="size-3.5" />}
                    Bloqué ? Forcer la résolution
                  </button>
                )}
              </div>
            )}

            {/* COMPLETED */}
            {run.status === "completed" && (
              <div className="grid gap-3">
                <div className="rounded-lg border border-border bg-surface p-4 text-center">
                  <p className="text-sm text-muted-foreground">Cette partie est terminée.</p>
                </div>
                {run.sessionId !== null && (
                  <RunRecapCard
                    isOwner={run.isOwner}
                    onChanged={refreshRun}
                    recapPublic={run.recapPublic}
                    runId={run.id}
                    sessionId={run.sessionId}
                  />
                )}
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
        {/* Ligne d'état pour qui ne pilote rien ici. Elle excluait draft et idle parce que la carte
            « mes jeux » couvrait ces deux états - mais elle n'apparaît que pour le propriétaire ou un
            participant. Un administrateur qui ouvre une partie dont il ne fait pas partie n'est ni
            l'un ni l'autre : sur une partie en brouillon ou en veille, sa vue d'ensemble était
            entièrement vide. */}
        {activeTab === "overview"
          && showsRunStatusLine(run.isOwner, myParticipant !== null, run.status) && (
          <section className="grid gap-2 rounded-lg border border-border bg-surface p-4">
            <p className="text-sm text-muted-foreground">
              {run.status === "draft" && "Cette partie n'est pas encore lancée."}
              {run.status === "idle" && "Cette partie est en veille."}
              {run.status === "starting" && "La partie est en cours de démarrage…"}
              {run.status === "active" && "La partie est en cours."}
              {run.status === "stopping" && "La partie est en cours d'arrêt…"}
              {run.status === "restarting" && "La partie redémarre…"}
              {run.status === "completed" && "La partie est terminée."}
              {run.status === "cancelled" && "La partie a été annulée."}
            </p>
            <p className="text-xs text-muted-foreground">
              {run.participants.length} participant{run.participants.length > 1 ? "s" : ""}
              {run.importedSeed === true && " · seed importée"}
              {myParticipant === null && " · tu ne participes pas à cette partie"}
            </p>
          </section>
        )}

        {/* Active: connection info for non-owner participants */}
        {activeTab === "overview" &&
          !run.isOwner &&
          run.status === "active" &&
          run.connectionHost !== null &&
          run.connectionPort !== null && (
            <ConnectionDetails
              host={run.connectionHost}
              password={run.connectionPassword}
              port={run.connectionPort}
              uri={run.connectionUri ?? null}
            />
          )}

        {/* Downloads - patch (each participant's slot) + full spoiler (owner/admin). Served from the
            durable MinIO archive, so available once generated whatever the state. */}
        {activeTab === "overview" && (
          <>
            <PersonalRunPatchPanel enabled={run.sessionId !== null} runId={run.id} />
            <PersonalRunSpoilerPanel
              enabled={(run.isOwner || isAdmin) && run.sessionId !== null}
              runId={run.id}
            />
          </>
        )}

        {/* Player progress grid + the live run timeline - visible to all when active or idle */}
        {activeTab === "progress" && run.sessionId && (
          <div className="grid gap-6">
            <PlayerProgressGrid personalRunId={run.id} runId={run.sessionId} />
            <LiveRunTimeline sessionId={run.sessionId} />
          </div>
        )}

        {/* Participants' Twitch streams (story 7.7) - dedicated tab, shows an empty state when none stream */}
        {activeTab === "streams" && <ParticipantStreams emptyState="message" id={run.id} kind="run" />}

        {/* OBS stream overlays - owner or admin only, when a session exists */}
        {activeTab === "overlay" && run.sessionId && (run.isOwner || isAdmin) && (
          <OverlayLinksPanel sessionId={run.sessionId} />
        )}

        {/* Participants section */}
        {activeTab === "participants" && (
          <section className="rounded-lg border border-border bg-surface p-4">
            <h2 className="mb-3 text-sm font-semibold text-foreground">
              Participants
              {run.participants.length > 0 && (
                <span className="ml-2 rounded-full border border-border px-2 py-0.5 text-xs font-normal text-muted-foreground">
                  {run.participants.length}
                </span>
              )}
            </h2>
            <ParticipantList participants={run.participants} runId={run.id} />
          </section>
        )}
      </div>
    </>
  );
}

function RunRecapCard({
  runId,
  sessionId,
  isOwner,
  recapPublic,
  onChanged,
}: {
  runId: string;
  sessionId: string;
  isOwner: boolean;
  recapPublic: boolean;
  onChanged: () => Promise<void>;
}) {
  const [busy, setBusy] = useState(false);

  async function toggle() {
    setBusy(true);
    const ok = await setRunRecapVisibility(runId, !recapPublic);
    if (ok) await onChanged();
    setBusy(false);
  }

  return (
    <div className="grid gap-3 rounded-lg border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="grid gap-0.5">
          <p className="font-heading text-sm font-semibold text-foreground">Récap de la partie</p>
          <p className="text-xs text-muted-foreground">
            {recapPublic
              ? "Public : partageable par lien."
              : "Privé : visible par toi et les participants."}
          </p>
        </div>
        <Link
          className="inline-flex shrink-0 items-center gap-2 rounded border border-border px-3 py-2 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          href={`/parties/${sessionId}`}
        >
          Voir le récap
        </Link>
      </div>
      {isOwner && (
        <button
          className="inline-flex w-full items-center justify-center gap-2 rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={busy}
          onClick={() => void toggle()}
          type="button"
        >
          {busy ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          {recapPublic ? "Rendre privé" : "Rendre public"}
        </button>
      )}
    </div>
  );
}
