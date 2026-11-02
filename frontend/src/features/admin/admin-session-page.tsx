"use client";

import {
  Activity,
  AlertTriangle,
  CheckCircle2,
  Copy,
  Download,
  Eye,
  EyeOff,
  Flag,
  Loader2,
  Play,
  RefreshCw,
  RotateCcw,
  Send,
  Server,
  Square,
  Terminal,
  Users,
  WifiOff,
  XCircle,
  Zap,
} from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { use, useCallback, useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { RequiredFilesPanel, useProvidedFiles } from "@/features/games/required-files-panel";
import type { GenerationFile } from "@/features/games/required-files";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME, REALTIME_STALE_TIME } from "@/lib/query-client";
import { useSSE } from "@/hooks/use-sse";
import { PlayerProgressGrid } from "@/components/session/PlayerProgressGrid";
import { isFeedEvent } from "@/features/overlay/overlay-api";
import { fetchSubscribeToken, isSessionStatusFrame, reconnectWithFreshToken } from "@/features/realtime/realtime-api";
import { OverlayLinksPanel } from "@/features/overlay/overlay-links-panel";
import { SessionPipelineBar } from "@/components/session/SessionPipeline";
import { clearOverride, fetchSessionConfig, loadOverride, saveOverride } from "@/features/admin/admin-session-config-api";
import { SessionConfigOverrideForm } from "@/features/admin/session-config-override-form";
import { sessionUptimeMs } from "@/features/admin/session-uptime";
import { CollapsibleConfigPanel } from "@/components/collapsible-config-panel";
import {
  fetchAdminEventSessions,
  fetchAdminEventTitle,
  fetchAdminSessionDetail,
  generateSession,
  fetchContainerLogs,
  forceLaunchSession,
  type AdminSessionDetailResult,
  type ForceLaunchResult,
  type SessionContainer,
  type Session,
  type SessionSlot,
  type SessionStatus,
} from "./admin-sessions-api";
import { GenerationFailureNotice, TechnicalLog } from "@/features/games/generation-failure-notice";

// ─── Types ───────────────────────────────────────────────────────────────────

// Guard for `/sessions/{id}` Mercure frames (story 33.19): structural check delegated to the
// shared session-frame guard; the refinement to the full Session shape is a documented trust
// decision - the api is the single publisher of this topic and publishes the complete payload.
function isSessionPayload(v: unknown): v is Session {
  return isSessionStatusFrame(v);
}

type BuilderOption = {
  key: string;
  required: boolean;
  defaultValue: boolean | number | string | null;
  currentValue: boolean | number | string | null;
};

type BuilderSlot = {
  slotId: string;
  gameId: string;
  slotOrder: number;
  gameName: string;
  archipelagoGameName: string | null;
  options: BuilderOption[];
};

type BuilderRegistration = {
  registrationId: string;
  playerName: string;
  slots: BuilderSlot[];
};

type WizardSlot = {
  registrationId: string;
  playerName: string;
  slotId: string;
  gameId: string;
  gameName: string;
  archipelagoGameName: string | null;
  slotName: string;
  errors: string[];
};

// ─── Page state ──────────────────────────────────────────────────────────────

// Local UI state of the "new session" wizard; the sessions list itself lives in TanStack Query.
type WizardState = {
  registrations: BuilderRegistration[] | null;
  builderLoading: boolean;
  slots: WizardSlot[];
};

// ─── Slot name generation (port of SlotNameGenerator.php) ───────────────────

function abbreviateGameName(gameName: string): string {
  if (!gameName.trim()) return "UNK";
  const words = gameName.trim().split(/\s+/);
  let abbr = "";
  for (const word of words) {
    const first = Array.from(word)[0];
    if (first) abbr += first.toLocaleUpperCase();
    if (charLength(abbr) >= 3) break;
  }
  return abbr || "UNK";
}

function charLength(value: string): number {
  return Array.from(value).length;
}

function limitChars(value: string, max: number): string {
  return Array.from(value).slice(0, max).join("");
}

function sanitizePlayerName(name: string): string {
  // Keep letters/digits/underscore (mirrors the backend SlotNameGenerator); drop the rest.
  const clean = name.replace(/[^A-Za-z0-9_]/g, "");
  return clean || "Player";
}

function generateSlotsFromRegistrations(registrations: BuilderRegistration[]): WizardSlot[] {
  const pairs = registrations.flatMap((reg) =>
    reg.slots.map((slot) => {
      const abbr = abbreviateGameName(slot.archipelagoGameName ?? "");
      const player = sanitizePlayerName(reg.playerName);
      const base = limitChars(player + "_" + abbr, 16);
      return { reg, slot, base };
    }),
  );

  const counts: Record<string, number> = {};
  pairs.forEach((p) => { counts[p.base] = (counts[p.base] ?? 0) + 1; });

  const counters: Record<string, number> = {};
  return pairs.map(({ reg, slot, base }) => {
    let slotName: string;
    if (counts[base] === 1) {
      slotName = base;
    } else {
      counters[base] = (counters[base] ?? 0) + 1;
      const suffix = String(counters[base]);
      slotName = limitChars(base, 16 - charLength(suffix)) + suffix;
    }
    return {
      registrationId: reg.registrationId,
      playerName: reg.playerName,
      slotId: slot.slotId,
      gameId: slot.gameId,
      gameName: slot.gameName,
      archipelagoGameName: slot.archipelagoGameName,
      slotName,
      errors: [],
    };
  });
}

// ─── Component ───────────────────────────────────────────────────────────────

export function AdminSessionPage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  const router = useRouter();
  const queryClient = useQueryClient();
  const [wizard, setWizard] = useState<WizardState | null>(null);
  const [restartingId, setRestartingId] = useState<string | null>(null);

  // queryFn throws on failure (denied / HTTP error / network) so a background refetch that fails
  // keeps the last known list on screen; the error panel only shows when nothing was ever loaded.
  const sessionsQuery = useQuery({
    queryKey: ["admin-event-sessions", eventId],
    queryFn: async () => {
      const result = await fetchAdminEventSessions(eventId);
      if (result.kind === "error") throw new Error(result.message);
      return result.sessions;
    },
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const eventTitleQuery = useQuery({
    queryKey: ["admin-event-title", eventId],
    queryFn: () => fetchAdminEventTitle(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const eventTitle = eventTitleQuery.data ?? null;
  const sessions = sessionsQuery.data;

  async function startWizard() {
    setWizard({ registrations: null, builderLoading: true, slots: [] });

    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/sessions/builder`);
      if (!res.ok) {
        setWizard(null);
        return;
      }
      const json = (await res.json()) as { data: { registrations: BuilderRegistration[] } };
      const registrations = json.data.registrations;
      const slots = generateSlotsFromRegistrations(registrations);
      setWizard((prev) => (prev !== null ? { registrations, builderLoading: false, slots } : prev));
    } catch {
      setWizard(null);
    }
  }

  async function createSession(slots: WizardSlot[], autoChain?: "generate-and-launch") {
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/sessions`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          slots: slots.map((s) => ({
            registrationId: s.registrationId,
            slotId: s.slotId,
            gameId: s.gameId,
            slotName: s.slotName,
          })),
        }),
      });
      if (!res.ok) {
        setWizard(null);
        return;
      }
      const json = (await res.json()) as { data: Session };
      const newSession = json.data;
      const url = `/admin/evenements/${eventId}/session/${newSession.id}${autoChain ? "?autoStart=1" : ""}`;
      router.push(url);
    } catch {
      setWizard(null);
    }
  }

  async function handleAdminRestart(sessionId: string) {
    setRestartingId(sessionId);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/restart`, { method: "POST" });
      if (res.ok) {
        await queryClient.invalidateQueries({ queryKey: ["admin-event-sessions", eventId] });
      }
    } catch {
      /* ignore - session card stays as-is */
    } finally {
      setRestartingId(null);
    }
  }

  if (sessionsQuery.isPending) {
    return (
      <PageShell eventId={eventId} eventTitle={eventTitle}>
        <SessionDetailSkeleton label="Chargement de la session..." />
      </PageShell>
    );
  }

  if (wizard !== null) {
    return (
      <PageShell eventId={eventId} eventTitle={eventTitle}>
        <WizardBuilder
          builderLoading={wizard.builderLoading}
          eventId={eventId}
          registrations={wizard.registrations}
          slots={wizard.slots}
          onBack={() => { setWizard(null); }}
          onSlotsChange={(slots) =>
            setWizard((prev) => (prev !== null ? { ...prev, slots } : prev))
          }
          onCreate={(slots) => createSession(slots)}
          onCreateAndGenerate={(slots) => createSession(slots, "generate-and-launch")}
        />
      </PageShell>
    );
  }

  if (sessions === undefined) {
    return (
      <PageShell eventId={eventId} eventTitle={eventTitle}>
        <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
          <XCircle aria-hidden="true" className="size-8 text-danger" />
          <p className="text-sm text-muted-foreground">
            {sessionsQuery.error instanceof Error
              ? sessionsQuery.error.message
              : "Impossible de charger les sessions."}
          </p>
        </div>
      </PageShell>
    );
  }

  // sessions list
  const idleSessions = sessions.filter((s) => s.status === "idle");
  const otherSessions = sessions.filter((s) => s.status !== "idle");

  return (
    <PageShell eventId={eventId} eventTitle={eventTitle}>
      <div className="flex items-center justify-between">
        <h2 className="font-heading text-2xl font-semibold text-foreground">Historique des sessions</h2>
        <button
          className="inline-flex min-h-9 items-center justify-center gap-2 rounded border border-border bg-accent px-4 text-sm font-semibold text-white hover:opacity-90"
          onClick={() => { void startWizard(); }}
          type="button"
        >
          <Play aria-hidden="true" className="size-4" />
          Nouvelle session
        </button>
      </div>

      {sessions.length === 0 ? (
        <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
          <Server aria-hidden="true" className="size-8 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">Aucune session pour cet événement.</p>
        </div>
      ) : (
        <div className="grid gap-6">
          {/* ── Sessions en pause ── */}
          {idleSessions.length > 0 ? (
            <div className="grid gap-3">
              <h3 className="text-sm font-semibold uppercase tracking-wide text-[var(--color-accent-warm)]">
                Sessions en pause
              </h3>
              {idleSessions.map((session) => (
                <div
                  className="flex flex-wrap items-center gap-3 rounded border border-[var(--color-accent-warm)]/30 bg-[var(--color-accent-warm)]/5 px-4 py-3"
                  key={session.id}
                >
                  <div className="grid min-w-0 flex-1 gap-0.5">
                    <p className="font-mono text-sm text-muted-foreground">{session.id.slice(0, 12)}…</p>
                    <p className="text-xs text-muted-foreground">
                      {formatDate(session.createdAt)}
                      {session.lastActivityAt ? ` · inactif depuis ${formatInactivity(session.lastActivityAt)}` : ""}
                    </p>
                  </div>

                  <StatusBadge status={session.status} />

                  <div className="flex items-center gap-2">
                    {session.pausedWithoutSave ? (
                      <span className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-3 text-xs font-medium text-muted-foreground opacity-60 cursor-not-allowed" title="Aucune sauvegarde disponible">
                        <RotateCcw aria-hidden="true" className="size-3.5" />
                        Reprendre
                      </span>
                    ) : (
                      <button
                        className="inline-flex min-h-8 items-center gap-1.5 rounded border border-[var(--color-accent-warm)]/50 bg-[var(--color-accent-warm)]/15 px-3 text-xs font-semibold text-[var(--color-accent-warm)] hover:bg-[var(--color-accent-warm)]/25 disabled:cursor-not-allowed disabled:opacity-60"
                        disabled={restartingId !== null}
                        onClick={() => { void handleAdminRestart(session.id); }}
                        type="button"
                      >
                        {restartingId === session.id ? (
                          <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
                        ) : (
                          <RotateCcw aria-hidden="true" className="size-3.5" />
                        )}
                        Reprendre
                      </button>
                    )}
                    <Link
                      className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-3 text-sm font-medium text-foreground hover:border-accent"
                      href={`/admin/evenements/${eventId}/session/${session.id}`}
                    >
                      Détail
                    </Link>
                  </div>
                </div>
              ))}
            </div>
          ) : null}

          {/* ── Historique ── */}
          {otherSessions.length > 0 ? (
            <div className="grid gap-3">
              {idleSessions.length > 0 ? (
                <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                  Historique
                </h3>
              ) : null}
              {otherSessions.map((session) => (
                <div
                  className="flex flex-wrap items-center gap-3 rounded border border-border bg-surface px-4 py-3"
                  key={session.id}
                >
                  <div className="grid min-w-0 flex-1 gap-0.5">
                    <p className="font-mono text-sm text-muted-foreground">{session.id.slice(0, 12)}…</p>
                    <p className="text-xs text-muted-foreground">
                      {[formatDate(session.createdAt), formatDuration(session)].filter(Boolean).join(" · ")}
                    </p>
                  </div>

                  <div className="flex items-center gap-2">
                    <StatusBadge status={session.status} />
                  </div>

                  <div className="flex items-center gap-2">
                    {["running", "stopped", "generated", "failed"].includes(session.status) ? (
                      <DownloadZipButton sessionId={session.id} compact />
                    ) : null}
                    <Link
                      className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-3 text-sm font-medium text-foreground hover:border-accent"
                      href={`/admin/evenements/${eventId}/session/${session.id}`}
                    >
                      Détail
                    </Link>
                  </div>
                </div>
              ))}
            </div>
          ) : null}
        </div>
      )}
    </PageShell>
  );
}

// ─── AdminSessionDetailPage ──────────────────────────────────────────────────

export function AdminSessionDetailPage({
  params,
}: {
  params: Promise<{ eventId: string; sessionId: string }>;
}) {
  const { eventId, sessionId } = use(params);
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();
  const autoStart = searchParams.get("autoStart") === "1" ? "generate-and-launch" as const : null;

  // queryFn throws on failure ("Session introuvable." / network) so a failed background refetch
  // keeps the currently displayed session; the error panel only shows when nothing was ever loaded.
  const detailQuery = useQuery({
    queryKey: ["admin-session-detail", sessionId],
    queryFn: async () => {
      const result = await fetchAdminSessionDetail(sessionId);
      if (result.kind === "error") throw new Error(result.message);
      return result;
    },
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const eventTitleQuery = useQuery({
    queryKey: ["admin-event-title", eventId],
    queryFn: () => fetchAdminEventTitle(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const eventTitle = eventTitleQuery.data ?? null;
  const detail = detailQuery.data;

  // SSE frames, the SSE fallback poll and action responses push the fresh session straight into
  // the cached detail, which stays the single source of truth for this page.
  const handleSessionUpdate = useCallback(
    (session: Session) => {
      queryClient.setQueryData<AdminSessionDetailResult>(
        ["admin-session-detail", sessionId],
        (prev) => (prev !== undefined && prev.kind === "ready" ? { ...prev, session } : prev),
      );
      // Review of epic 44: a failed generation may have just proved a game needs a file (a ROM). The pushed
      // session does not carry the files, so the detail is read again and the panel asks for it.
      if (session.status === "failed") {
        void queryClient.invalidateQueries({ queryKey: ["admin-session-detail", sessionId] });
      }
    },
    [queryClient, sessionId],
  );

  if (detail === undefined && detailQuery.isError) {
    return (
      <PageShell eventId={eventId} eventTitle={eventTitle} sessionId={sessionId}>
        <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
          <XCircle aria-hidden="true" className="size-8 text-danger" />
          <p className="text-sm text-muted-foreground">
            {detailQuery.error instanceof Error ? detailQuery.error.message : "Session introuvable."}
          </p>
        </div>
      </PageShell>
    );
  }

  if (detail === undefined || detail.kind !== "ready") {
    return (
      <PageShell eventId={eventId} eventTitle={eventTitle} sessionId={sessionId}>
        <SessionDetailSkeleton label="Chargement de la session..." />
      </PageShell>
    );
  }

  return (
    <PageShell eventId={eventId} eventTitle={eventTitle} sessionId={sessionId}>
      <SessionDetail
        autoStart={autoStart}
        eventId={eventId}
        onSessionUpdate={handleSessionUpdate}
        generationFiles={detail.generationFiles}
        session={detail.session}
        slots={detail.slots}
      />
      <OverlayLinksPanel sessionId={sessionId} />
      <CollapsibleConfigPanel title="Configuration avancée (override de la session)">
        <SessionConfigOverrideForm
          adapter={{
            queryKey: ["session-override", "event-session", sessionId],
            load: () => loadOverride(`/admin/session-config/override/${sessionId}`),
            loadProfile: () => fetchSessionConfig("event"),
            save: (o) => saveOverride(`/admin/session-config/override/${sessionId}`, o),
            clear: () => clearOverride(`/admin/session-config/override/${sessionId}`),
          }}
          scopeLabel="cette session"
        />
      </CollapsibleConfigPanel>
    </PageShell>
  );
}

// ─── PageShell ────────────────────────────────────────────────────────────────

function PageShell({
  eventId,
  eventTitle,
  sessionId,
  children,
}: {
  eventId: string;
  eventTitle: string | null;
  sessionId?: string;
  children: React.ReactNode;
}) {
  return (
    <section className="grid w-full gap-8 px-4 py-10">
      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Backoffice · Sessions
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
          {eventTitle ?? <BouncingDots />}
        </h1>
        <p className="mt-1 font-mono text-sm text-muted-foreground">{eventId}</p>
        {sessionId ? (
          <nav className="mt-3 flex items-center gap-2 text-sm text-muted-foreground">
            <Link className="hover:text-foreground" href={`/admin/evenements/${eventId}/session`}>
              Toutes les sessions
            </Link>
            <span aria-hidden="true">/</span>
            <span className="font-mono text-foreground">{sessionId.slice(0, 12)}…</span>
          </nav>
        ) : null}
      </header>
      {children}
    </section>
  );
}

// ─── WizardBuilder ────────────────────────────────────────────────────────────

function SessionDetailSkeleton({ label }: { label: string }) {
  return (
    <div className="grid gap-4">
      <span className="sr-only">{label}</span>
      <div aria-hidden="true" className="grid gap-4">
        <div className="flex min-w-0 items-center gap-1">
          {[0, 1, 2, 3].map((i) => (
            <div className="flex flex-1 items-center gap-1" key={i}>
              <div className="size-7 shrink-0 animate-pulse rounded-full bg-surface-2" />
              {i < 3 ? <div className="h-px flex-1 animate-pulse rounded bg-surface-2" /> : null}
            </div>
          ))}
        </div>
        <div className="flex gap-2">
          <div className="h-9 w-36 animate-pulse rounded bg-surface-2" />
          <div className="h-9 w-28 animate-pulse rounded bg-surface-2" />
          <div className="hidden h-9 w-24 animate-pulse rounded bg-surface-2 sm:block" />
        </div>
      </div>
    </div>
  );
}

function validateSlots(slots: WizardSlot[]): Record<string, string[]> {
  const names = slots.map((s) => s.slotName);
  const errors: Record<string, string[]> = {};
  slots.forEach((slot) => {
    const errs: string[] = [];
    if (!slot.slotName.trim()) errs.push("Nom requis");
    else if (charLength(slot.slotName) > 16) errs.push("Maximum 16 caractères");
    if (slot.slotName && names.filter((n) => n === slot.slotName).length > 1) errs.push("Nom déjà utilisé");
    if (errs.length) errors[slot.slotId] = errs;
  });
  return errors;
}

function WizardBuilder({
  builderLoading,
  eventId,
  registrations,
  slots,
  onBack,
  onSlotsChange,
  onCreate,
  onCreateAndGenerate,
}: {
  builderLoading: boolean;
  eventId: string;
  registrations: BuilderRegistration[] | null;
  slots: WizardSlot[];
  onBack: () => void;
  onSlotsChange: (slots: WizardSlot[]) => void;
  onCreate: (slots: WizardSlot[]) => Promise<void>;
  onCreateAndGenerate: (slots: WizardSlot[]) => Promise<void>;
}) {
  const [pendingCreate, setPendingCreate] = useState<"create" | "create-generate" | null>(null);
  const errors = validateSlots(slots);
  const isInvalid = Object.keys(errors).length > 0 || slots.some((s) => !s.slotName.trim());

  function updateSlotName(slotId: string, name: string) {
    onSlotsChange(slots.map((s) => s.slotId === slotId ? { ...s, slotName: name } : s));
  }

  function regenerateNames() {
    if (!registrations) return;
    const generated = generateSlotsFromRegistrations(registrations);
    onSlotsChange(generated);
  }

  return (
    <div className="grid gap-6">
      <div className="flex items-center justify-between">
        <h2 className="font-heading text-2xl font-semibold text-foreground">
          Créer une session
        </h2>
        <button className="text-sm text-muted-foreground hover:text-foreground" onClick={onBack} type="button">
          Annuler
        </button>
      </div>

      {builderLoading ? (
        <>
          <span className="sr-only">Chargement des inscriptions...</span>
          <div aria-hidden="true" className="overflow-x-auto border border-border bg-surface">
            <table className="w-full border-collapse text-left text-sm">
              <tbody>
                {[0, 1, 2].map((i) => (
                  <tr className="border-b border-border" key={i}>
                    <td className="px-4 py-3"><div className="h-4 w-32 animate-pulse rounded bg-surface-2" /></td>
                    <td className="px-4 py-3"><div className="h-4 w-48 animate-pulse rounded bg-surface-2" /></td>
                    <td className="px-4 py-3"><div className="h-7 w-36 animate-pulse rounded bg-surface-2" /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ) : registrations === null ? (
        <p className="text-sm text-danger">Impossible de charger les inscriptions.</p>
      ) : registrations.length === 0 ? (
        <div className="grid justify-items-center gap-4 border border-border bg-surface p-10 text-center">
          <Users aria-hidden="true" className="size-10 text-muted-foreground" />
          <p className="font-heading text-lg font-semibold text-foreground">Aucune inscription confirmée</p>
          <p className="text-sm text-muted-foreground">Confirmez des inscriptions avant de créer une session.</p>
          <Link
            className="inline-flex min-h-9 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground hover:border-accent"
            href={`/admin/evenements/${eventId}/inscriptions`}
          >
            Voir les inscriptions
          </Link>
        </div>
      ) : (
        <div className="grid gap-4">
          <div className="overflow-x-auto border border-border bg-surface">
            <table className="w-full border-collapse text-left text-sm">
              <thead className="border-b border-border text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 font-medium">Joueur</th>
                  <th className="px-4 py-3 font-medium">Jeu</th>
                  <th className="px-4 py-3 font-medium">
                    <div className="flex items-center justify-between gap-3">
                      <span>Nom de slot</span>
                      <button
                        className="text-xs text-accent-text hover:underline"
                        onClick={regenerateNames}
                        type="button"
                      >
                        Régénérer les noms
                      </button>
                    </div>
                  </th>
                </tr>
              </thead>
              <tbody>
                {slots.map((slot) => {
                  const slotErrors = errors[slot.slotId] ?? [];
                  const hasError = slotErrors.length > 0;
                  return (
                    <tr className="border-b border-border last:border-b-0" key={slot.slotId}>
                      <td className="px-4 py-3 font-semibold text-foreground">{slot.playerName}</td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">
                        {slot.gameName}
                        {slot.archipelagoGameName === null ? (
                          <span className="ml-2 rounded border border-danger/40 bg-danger/10 px-1.5 py-0.5 text-danger">
                            YAML non configuré
                          </span>
                        ) : null}
                      </td>
                      <td className="px-4 py-3">
                        <div className="grid gap-1">
                          <div className="flex items-center gap-2">
                            <input
                              className={`w-40 rounded border bg-background px-3 py-1.5 text-sm font-mono text-foreground outline-none focus:border-accent ${hasError ? "border-danger" : "border-border"}`}
                              onChange={(e) => { updateSlotName(slot.slotId, e.target.value); }}
                              type="text"
                              value={slot.slotName}
                            />
                            <span className="text-xs text-muted-foreground shrink-0">({charLength(slot.slotName)}/16)</span>
                          </div>
                          {slotErrors.map((err) => (
                            <p className="text-xs text-danger" key={err}>{err}</p>
                          ))}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <div className="flex justify-end gap-2">
            <button
              className="inline-flex min-h-9 min-w-36 items-center justify-center gap-2 rounded border border-border px-4 text-sm font-semibold text-foreground hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
              disabled={isInvalid || pendingCreate !== null}
              onClick={() => {
                setPendingCreate("create");
                void onCreate(slots).finally(() => { setPendingCreate(null); });
              }}
              type="button"
            >
              {pendingCreate === "create" ? <Loader2 aria-hidden="true" className="size-4 animate-spin" /> : null}
              Créer la session
            </button>
            <button
              className="inline-flex min-h-9 min-w-44 items-center justify-center gap-2 rounded border border-border bg-accent px-4 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
              disabled={isInvalid || pendingCreate !== null}
              onClick={() => {
                setPendingCreate("create-generate");
                void onCreateAndGenerate(slots).finally(() => { setPendingCreate(null); });
              }}
              type="button"
            >
              {pendingCreate === "create-generate"
                ? <Loader2 aria-hidden="true" className="size-4 animate-spin" />
                : <Zap aria-hidden="true" className="size-4" />}
              Créer &amp; Générer
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── SessionDetail ────────────────────────────────────────────────────────────

function SessionDetail({
  session,
  slots,
  onSessionUpdate,
  eventId,
  autoStart = null,
  generationFiles,
}: {
  session: Session;
  slots: SessionSlot[];
  onSessionUpdate: (session: Session) => void;
  eventId: string;
  autoStart?: "generate-and-launch" | null;
  generationFiles: GenerationFile[];
}) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const [actionPending, setActionPending] = useState<string | null>(null);
  const [pendingChain, setPendingChain] = useState<"generate" | "generate-and-launch" | null>(autoStart);
  // Story 44.5: the files a generation needs (a ROM), chosen and checked here, never stored.
  const provided = useProvidedFiles(generationFiles);
  const [actionError, setActionError] = useState<string | null>(null);
  const filesMissing = provided.blocker !== null;
  const [copied, setCopied] = useState<string | null>(null);
  const [forceEndOpen, setForceEndOpen] = useState(false);
  const [passwordVisible, setPasswordVisible] = useState(false);
  const [adminPasswordVisible, setAdminPasswordVisible] = useState(false);
  const onSessionUpdateRef = useRef(onSessionUpdate);
  useEffect(() => { onSessionUpdateRef.current = onSessionUpdate; }, [onSessionUpdate]);

  function clearPendingChainSoon() {
    queueMicrotask(() => {
      setPendingChain(null);
    });
  }

  function runChainedAction(action: string) {
    void runAction(action).then((ok) => {
      if (!ok) {
        setPendingChain(null);
      }
    });
  }

  // Reactive chain: drive validate→generate→launch pipeline via SSE status updates
  useEffect(() => {
    if (pendingChain === null) return;
    if (actionPending !== null) return; // wait for current HTTP call to finish

    if (session.status === "draft" && session.validationErrors && session.validationErrors.length > 0) {
      clearPendingChainSoon();
      return;
    }

    // From draft: trigger validate to start the chain (handles autoStart case on mount)
    if (session.status === "draft") {
      runChainedAction("validate");
      return;
    }
    if (session.status === "ready") {
      // Story 44.5: the chain stops here until the admin provides the files a game needs.
      if (filesMissing) {
        clearPendingChainSoon();
        return;
      }
      runChainedAction("generate");
      return;
    }
    if (session.status === "generated") {
      if (pendingChain === "generate-and-launch") {
        runChainedAction("launch");
      } else {
        clearPendingChainSoon();
      }
      return;
    }
    if (["running", "stopped", "finished", "failed", "crashed"].includes(session.status)) {
      clearPendingChainSoon();
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps -- runChainedAction/clearPendingChainSoon are recreated each render but only close over stable setters; the chain must fire on status/chain/pending transitions only
  }, [session.status, pendingChain, actionPending]);

  const handleSSEMessage = useCallback((data: Session) => {
    onSessionUpdateRef.current(data);
  }, []);

  const fallbackPoll = useCallback(async () => {
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${session.id}`);
      if (!res.ok) return;
      const json = (await res.json()) as { data: { session: Session } };
      onSessionUpdateRef.current(json.data.session);
    } catch {
      /* keep current session state */
    }
  }, [session.id]);

  useSSE<Session>(
    `/sessions/${session.id}`,
    env.mercurePublicUrl || null,
    isSessionPayload,
    handleSSEMessage,
    fallbackPoll,
  );

  async function runAction(action: string): Promise<boolean> {
    setActionPending(action);
    setActionError(null);
    if (action === "generate") {
      const result = await generateSession(session.id, provided.files);
      setActionPending(null);
      if (!result.ok) {
        setActionError(result.message);
        return false;
      }
      // The files went with the generation: nothing is kept for later.
      provided.reset();
      onSessionUpdate(result.session);
      return true;
    }
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${session.id}/${action}`, {
        method: "POST",
      });
      if (res.ok) {
        const json = (await res.json()) as { data: Session };
        onSessionUpdate(json.data);
        return true;
      }
      return false;
    } catch {
      return false;
    } finally {
      setActionPending(null);
    }
  }

  function copyToClipboard(value: string, key: string) {
    void navigator.clipboard.writeText(value).then(() => {
      setCopied(key);
      setTimeout(() => { setCopied(null); }, 2000);
    });
  }

  type TabId = "overview" | "slots" | "terminal" | "container";
  const TABS: { id: TabId; label: string }[] = [
    { id: "overview", label: "Vue d'ensemble" },
    { id: "slots", label: "Progression" },
    { id: "terminal", label: "Terminal" },
    { id: "container", label: "Logs" },
  ];
  const VALID_TABS = new Set<string>(TABS.map((t) => t.id));
  const rawTab = searchParams.get("tab");
  const [activeTab, setActiveTab] = useState<TabId>(
    VALID_TABS.has(rawTab ?? "") ? (rawTab as TabId) : "overview"
  );

  function switchTab(tab: TabId) {
    setActiveTab(tab);
    const params = new URLSearchParams(searchParams.toString());
    if (tab === "overview") {
      params.delete("tab");
    } else {
      params.set("tab", tab);
    }
    const qs = params.toString();
    router.replace(`${pathname}${qs ? `?${qs}` : ""}`, { scroll: false });
  }

  const isProcessing = ["validating", "generating", "launching"].includes(session.status);

  return (
    <div className="grid gap-0">
      {/* ── Sticky action bar ── */}
      <div className="sticky top-0 z-10 flex items-center gap-4 border-b border-border bg-surface/95 px-5 py-2.5 backdrop-blur-sm">

        {/* Left - session context */}
        <div className="flex min-w-0 flex-1 items-center gap-3">
          <span
            aria-hidden="true"
            className={`size-2 shrink-0 rounded-full ${
              session.status === "running"
                ? "animate-pulse bg-success"
                : isProcessing
                  ? "animate-pulse bg-accent-warm"
                  : "bg-border"
            }`}
          />
          <StatusBadge status={session.status} />
          <div aria-hidden="true" className="hidden h-3.5 w-px bg-border lg:block" />
          <span className="hidden text-xs text-muted-foreground/70 lg:block">
            Créée {formatDate(session.createdAt)}
            {launchSummary(session)}
          </span>
        </div>

        {/* Right - actions */}
        <div className="flex shrink-0 items-center gap-2">
          {/* Download (utility, always secondary) */}
          {["running", "stopped", "generated", "failed"].includes(session.status) ? (
            <>
              <DownloadZipButton sessionId={session.id} />
              <div aria-hidden="true" className="h-5 w-px bg-border" />
            </>
          ) : null}

          {/* draft / ready */}
          {(session.status === "draft" || session.status === "ready") ? (
            <>
              <ActionButton
                compact
                disabled={pendingChain !== null || filesMissing}
                icon={<Activity aria-hidden="true" className="size-4" />}
                label="Générer"
                loading={pendingChain === "generate" || ((actionPending === "validate" || actionPending === "generate") && pendingChain === null)}
                onClick={() => { setPendingChain("generate"); runChainedAction("validate"); }}
                variant="secondary"
              />
              <ActionButton
                compact
                disabled={(pendingChain !== null && pendingChain !== "generate-and-launch") || filesMissing}
                icon={<Zap aria-hidden="true" className="size-4" />}
                label="Générer & Lancer"
                loading={pendingChain !== null || actionPending !== null}
                onClick={() => { setPendingChain("generate-and-launch"); runChainedAction("validate"); }}
              />
            </>
          ) : null}

          {/* generated */}
          {session.status === "generated" ? (
            <>
              <ActionButton
                compact
                disabled={pendingChain !== null}
                icon={<Zap aria-hidden="true" className="size-4" />}
                label="Régénérer & Lancer"
                loading={pendingChain === "generate-and-launch"}
                onClick={() => { setPendingChain("generate-and-launch"); runChainedAction("launch"); }}
                variant="secondary"
              />
              <ActionButton
                compact
                disabled={pendingChain !== null}
                icon={<Play aria-hidden="true" className="size-4" />}
                label="Lancer"
                loading={actionPending === "launch" && pendingChain === null}
                onClick={() => { void runAction("launch"); }}
              />
            </>
          ) : null}

          {/* running */}
          {session.status === "running" ? (
            <>
              <ActionButton
                compact
                icon={<Square aria-hidden="true" className="size-4" />}
                label="Arrêter"
                loading={actionPending === "stop"}
                onClick={() => { void runAction("stop"); }}
                variant="warning"
              />
              <div aria-hidden="true" className="h-5 w-px bg-border" />
              <ActionButton
                compact
                icon={<Flag aria-hidden="true" className="size-4" />}
                label="Forcer la fin"
                loading={actionPending === "force-end"}
                onClick={() => { setForceEndOpen(true); }}
                variant="danger"
              />
            </>
          ) : null}

          {/* failed - retry generation */}
          {session.status === "failed" ? (
            <>
              <ActionButton
                compact
                disabled={pendingChain !== null || filesMissing}
                icon={<RefreshCw aria-hidden="true" className="size-4" />}
                label="Régénérer"
                loading={pendingChain === "generate" || ((actionPending === "validate" || actionPending === "generate") && pendingChain === null)}
                onClick={() => { setPendingChain("generate"); runChainedAction("validate"); }}
                variant="secondary"
              />
              <ActionButton
                compact
                disabled={(pendingChain !== null && pendingChain !== "generate-and-launch") || filesMissing}
                icon={<Zap aria-hidden="true" className="size-4" />}
                label="Régénérer & Lancer"
                loading={pendingChain !== null || actionPending !== null}
                onClick={() => { setPendingChain("generate-and-launch"); runChainedAction("validate"); }}
              />
            </>
          ) : null}

          {/* crashed */}
          {session.status === "crashed" ? (
            <ActionButton
              compact
              icon={<RefreshCw aria-hidden="true" className="size-4" />}
              label="Relancer"
              loading={actionPending === "restart"}
              onClick={() => { void runAction("restart"); }}
            />
          ) : null}
        </div>
      </div>

      {/* Story 44.5: the files a generation needs, and why a generation could not start. */}
      {["draft", "ready", "failed"].includes(session.status) && (provided.needed.length > 0 || actionError !== null) ? (
        <div className="grid gap-3 border-b border-border px-5 py-3">
          <RequiredFilesPanel disabled={actionPending !== null} needed={provided.needed} onChoose={(required, file) => void provided.choose(required, file)} states={provided.states} />
          {provided.blocker !== null ? <p className="text-xs text-muted-foreground">{provided.blocker}</p> : null}
          {actionError !== null ? <p className="text-sm text-danger" role="alert">{actionError}</p> : null}
        </div>
      ) : null}

      {/* ── Tab bar ── one scrollable row on every width, same pattern as the run page (16.12). */}
      <div className="flex gap-1 overflow-x-auto border-b border-border bg-surface px-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {TABS.map((tab) => (
          <button
            className={`shrink-0 whitespace-nowrap px-4 py-3 text-sm font-medium transition-colors ${
              activeTab === tab.id
                ? "border-b-2 border-accent-text text-foreground"
                : "text-muted-foreground hover:text-foreground"
            }`}
            key={tab.id}
            onClick={() => { switchTab(tab.id); }}
            type="button"
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* ── Tab content ── */}
      <div className="grid gap-6 p-4 pt-6">

        {/* ── Vue d'ensemble ── */}
        {activeTab === "overview" ? (
          <div className="grid gap-5">

            {/* Pipeline + meta */}
            <div className="rounded border border-border bg-surface p-5">
              <SessionPipelineBar status={session.status} />
            </div>

            {/* A crashed server: the AP server stopped, nothing to read but its logs. */}
            {session.status === "crashed" ? (
              <div className="grid gap-0 overflow-hidden rounded border border-danger/40">
                <div className="flex items-start gap-3 bg-danger/10 px-4 py-3 text-sm text-danger">
                  <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                  <span>{session.error ?? "La session a planté. Vérifiez le runner avant de relancer."}</span>
                </div>
                {session.lastLogs ? (
                  <pre className="max-h-48 overflow-y-auto bg-[var(--color-bg)] px-4 py-3 font-mono text-xs text-danger/70 whitespace-pre-wrap">
                    {session.lastLogs}
                  </pre>
                ) : null}
              </div>
            ) : null}

            {/* Story 38.16: a failed generation or a refused validation, one notice per finding, the log once. */}
            {session.status === "failed" || (session.validationErrors && session.validationErrors.length > 0) ? (
              <div className="grid gap-3 rounded border border-danger/40 bg-danger/5 p-4">
                <p className="flex items-center gap-2 text-sm font-semibold text-danger">
                  <AlertTriangle aria-hidden="true" className="size-4" />
                  {session.status === "failed" ? "La génération a échoué." : "Erreurs de validation : corrigez les YAMLs puis relancez la génération."}
                </p>
                {session.validationErrors && session.validationErrors.length > 0 ? (
                  <div className="grid divide-y divide-danger/20">
                    {session.validationErrors.flatMap((ve) =>
                      ve.errors.map((err, i) => (
                        <div className="py-2 first:pt-0 last:pb-0" key={`${ve.slotName}-${i}`}>
                          <GenerationFailureNotice audience="admin" bare message={err} slotName={ve.slotName} />
                        </div>
                      )),
                    )}
                  </div>
                ) : (
                  <GenerationFailureNotice audience="admin" bare message={session.error ?? ""} />
                )}
                {session.status === "failed" && session.lastLogs ? <TechnicalLog log={session.lastLogs} /> : null}
              </div>
            ) : null}

            {/* Connection info */}
            {session.status === "running" && session.host !== null ? (
              <div className="rounded border border-success/30 bg-success/5 p-5">
                <p className="mb-3 text-xs font-semibold uppercase tracking-widest text-success/70">Connexion</p>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <ConnectionField copyLabel="Copier l'adresse" copied={copied === "host"} label="Adresse"
                    onCopy={() => { copyToClipboard(session.host ?? "", "host"); }} value={session.host ?? ""} />
                  <ConnectionField copyLabel="Copier le port" copied={copied === "port"} label="Port"
                    onCopy={() => { copyToClipboard(String(session.port ?? ""), "port"); }} value={String(session.port ?? "")} />
                  <ConnectionField
                    copyLabel="Copier le mot de passe joueur"
                    copied={copied === "password"}
                    hidden={!passwordVisible}
                    label="Mot de passe"
                    onCopy={() => { copyToClipboard(session.password ?? "", "password"); }}
                    onToggleHidden={() => { setPasswordVisible((v) => !v); }}
                    value={session.password ?? ""}
                  />
                  <ConnectionField
                    copyLabel="Copier le mot de passe admin"
                    copied={copied === "serverPassword"}
                    hidden={!adminPasswordVisible}
                    label="Mot de passe admin"
                    onCopy={() => { copyToClipboard(session.serverPassword ?? "", "serverPassword"); }}
                    onToggleHidden={() => { setAdminPasswordVisible((v) => !v); }}
                    value={session.serverPassword ?? ""}
                  />
                </div>
              </div>
            ) : null}

            {/* Slots */}
            {slots.length > 0 ? (
              <div className="overflow-x-auto rounded border border-border bg-surface">
                <table className="w-full border-collapse text-left text-sm">
                  <thead className="border-b border-border text-muted-foreground">
                    <tr>
                      <th className="px-4 py-3 font-medium">#</th>
                      <th className="px-4 py-3 font-medium">Nom de slot</th>
                      <th className="px-4 py-3 font-medium">Inscription</th>
                    </tr>
                  </thead>
                  <tbody>
                    {slots.map((slot) => (
                      <tr className="border-b border-border last:border-b-0" key={slot.id}>
                        <td className="px-4 py-3 text-muted-foreground">{slot.slotOrder + 1}</td>
                        <td className="px-4 py-3 font-mono font-semibold text-foreground">{slot.slotName}</td>
                        <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{slot.registrationId.slice(0, 8)}…</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}
          </div>
        ) : null}

        {/* ── Joueurs ── */}
        {activeTab === "slots" ? (
          <PlayerProgressGrid eventId={eventId} runId={session.id} />
        ) : null}

        {/* ── Terminal ── */}
        {activeTab === "terminal" ? (
          <AdminTerminal
            canSendCommands={session.status === "running"}
            runId={session.id}
            sessionId={session.id}
            slotNames={slots.map((s) => s.slotName)}
          />
        ) : null}

        {/* ── Container ── */}
        {activeTab === "container" ? (
          <LogPanel active={session.status === "running"} sessionId={session.id} />
        ) : null}

      </div>

      {/* Force-end confirmation dialog */}
      {forceEndOpen ? (
        <ForceEndDialog
          onCancel={() => { setForceEndOpen(false); }}
          onConfirm={async () => {
            setForceEndOpen(false);
            setActionPending("force-end");
            try {
              const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${session.id}/force-end`, { method: "POST" });
              if (res.ok) {
                const json = (await res.json()) as { data: Session };
                onSessionUpdate(json.data);
              }
            } catch {
              /* ignore */
            } finally {
              setActionPending(null);
            }
          }}
        />
      ) : null}
    </div>
  );
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function ActionButton({
  icon,
  label,
  loading,
  onClick,
  variant = "default",
  disabled,
  compact = false,
}: {
  icon: React.ReactNode;
  label: string;
  loading: boolean;
  onClick: () => void;
  variant?: "default" | "secondary" | "warning" | "danger";
  disabled?: boolean;
  compact?: boolean;
}) {
  const cls =
    variant === "danger"
      ? "border-danger/60 bg-danger/20 text-danger hover:bg-danger/30"
      : variant === "warning"
        ? "border-accent-warm/50 bg-accent-warm/15 text-accent-warm hover:bg-accent-warm/25"
      : variant === "secondary"
        ? "border-border bg-surface-2 text-foreground hover:border-accent"
        : "border-border bg-accent text-white hover:opacity-90";

  return (
    <button
      className={`inline-flex items-center justify-center gap-1.5 rounded border font-semibold disabled:cursor-not-allowed disabled:opacity-60 ${compact ? "min-h-8 px-3 text-xs" : "min-h-9 min-w-36 px-4 text-sm"} ${cls}`}
      disabled={loading || disabled}
      onClick={onClick}
      type="button"
    >
      {loading ? <Loader2 aria-hidden="true" className="size-3.5 animate-spin" /> : icon}
      {label}
    </button>
  );
}

function DownloadZipButton({ sessionId, compact = false }: { sessionId: string; compact?: boolean }) {
  const href = `${env.apiBaseUrl}/admin/sessions/${sessionId}/generation.zip`;

  if (compact) {
    return (
      <a
        aria-label="Télécharger le .zip de la génération"
        className="inline-flex min-h-8 items-center justify-center rounded border border-border px-2 text-foreground hover:border-accent"
        download={`session-${sessionId}-generation.zip`}
        href={href}
      >
        <Download aria-hidden="true" className="size-4" />
      </a>
    );
  }

  return (
    <a
      className="inline-flex min-h-8 items-center justify-center gap-1.5 rounded border border-border px-3 text-xs font-semibold text-foreground hover:border-accent"
      download={`session-${sessionId}-generation.zip`}
      href={href}
    >
      <Download aria-hidden="true" className="size-3.5" />
      .zip
    </a>
  );
}

function ConnectionField({
  label,
  value,
  onCopy,
  copied,
  copyLabel,
  hidden = false,
  onToggleHidden,
}: {
  label: string;
  value: string;
  onCopy: () => void;
  copied: boolean;
  copyLabel?: string;
  hidden?: boolean;
  onToggleHidden?: () => void;
}) {
  return (
    <div className="grid gap-1">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className="flex items-center gap-2 rounded border border-border bg-[var(--color-bg)] px-3 py-2">
        <span className="flex-1 font-mono text-sm font-semibold text-foreground">
          {hidden ? "••••••••" : value}
        </span>
        {onToggleHidden ? (
          <button
            aria-label={hidden ? "Afficher" : "Masquer"}
            className="text-muted-foreground hover:text-foreground"
            onClick={onToggleHidden}
            type="button"
          >
            {hidden
              ? <Eye aria-hidden="true" className="size-4" />
              : <EyeOff aria-hidden="true" className="size-4" />}
          </button>
        ) : null}
        <button
          aria-label={copyLabel ?? `Copier ${label}`}
          className="text-muted-foreground hover:text-foreground"
          onClick={onCopy}
          type="button"
        >
          {copied
            ? <CheckCircle2 aria-hidden="true" className="size-4 text-success" />
            : <Copy aria-hidden="true" className="size-4" />}
        </button>
      </div>
    </div>
  );
}

// ─── AdminTerminal ────────────────────────────────────────────────────────────

// ─── Archipelago command definitions ─────────────────────────────────────────

type CmdArg = "player" | "item" | "amount" | "seconds";

type CmdDef = { name: string; signature: string; description: string; args: CmdArg[] };

const ARCHIPELAGO_COMMANDS: CmdDef[] = [
  // ── Admin commands (!) ───────────────────────────────────────────────────────
  { name: "!players",        signature: "!players",                           description: "Liste les joueurs connectés",           args: [] },
  { name: "!kick",           signature: "!kick <joueur>",                     description: "Expulse un joueur",                     args: ["player"] },
  { name: "!hint",           signature: "!hint <joueur> <item>",              description: "Indice admin pour n'importe quel slot",  args: ["player", "item"] },
  { name: "!hint_location",  signature: "!hint_location <joueur> <loc>",      description: "Indice admin pour une location",         args: ["player", "item"] },
  { name: "!release",        signature: "!release <joueur>",                  description: "Libère les items d'un joueur",           args: ["player"] },
  { name: "!collect",        signature: "!collect <joueur>",                  description: "Collecte les items pour un joueur",      args: ["player"] },
  { name: "!forfeit",        signature: "!forfeit <joueur>",                  description: "Abandonne pour un joueur",               args: ["player"] },
  { name: "!send",           signature: "!send <joueur> <item>",              description: "Envoie un item à un joueur",             args: ["player", "item"] },
  { name: "!send_multiple",  signature: "!send_multiple <n> <item> <joueur>", description: "Envoie N copies d'un item à un joueur",  args: ["amount", "item", "player"] },
  { name: "!missing",        signature: "!missing <joueur>",                  description: "Items manquants d'un joueur",            args: ["player"] },
  { name: "!checked",        signature: "!checked <joueur>",                  description: "Locations vérifiées d'un joueur",        args: ["player"] },
  { name: "!goal",           signature: "!goal <joueur>",                     description: "Marque l'objectif comme atteint",        args: ["player"] },
  { name: "!countdown",      signature: "!countdown <secondes>",              description: "Lance un compte à rebours",              args: ["seconds"] },
  { name: "!games",          signature: "!games",                             description: "Liste les jeux de la session",           args: [] },
  { name: "!close",          signature: "!close",                             description: "Ferme la room",                         args: [] },
  { name: "!allow_release",  signature: "!allow_release",                     description: "Autorise les releases",                  args: [] },
  { name: "!forbid_release", signature: "!forbid_release",                    description: "Interdit les releases",                  args: [] },
  { name: "!allow_collect",  signature: "!allow_collect",                     description: "Autorise les collects",                  args: [] },
  { name: "!forbid_collect", signature: "!forbid_collect",                    description: "Interdit les collects",                  args: [] },
];

type Suggestion = { text: string; description?: string };

function getSuggestions(input: string, slotNames: string[]): Suggestion[] {
  if (!input.startsWith("!")) return [];
  const parts = input.split(" ");

  if (parts.length === 1) {
    const partial = parts[0].toLowerCase();
    return ARCHIPELAGO_COMMANDS
      .filter((c) => c.name.startsWith(partial) && c.name !== partial)
      .map((c) => ({ text: c.name, description: c.description }))
      .slice(0, 8);
  }

  const cmdDef = ARCHIPELAGO_COMMANDS.find((c) => c.name === parts[0].toLowerCase());
  if (!cmdDef) return [];

  const argIdx = parts.length - 2;
  const argType = cmdDef.args[argIdx];
  if (argType !== "player") return [];

  const current = parts[parts.length - 1].toLowerCase();
  const prefix = parts.slice(0, -1).join(" ");
  return slotNames
    .filter((s) => s.toLowerCase().startsWith(current) && s.toLowerCase() !== current)
    .map((s) => ({ text: `${prefix} ${s}` }))
    .slice(0, 8);
}

function getSignatureHint(input: string): string | null {
  const parts = input.split(" ");
  if (parts.length < 2) return null;
  const cmdDef = ARCHIPELAGO_COMMANDS.find((c) => c.name === parts[0].toLowerCase());
  return cmdDef?.signature ?? null;
}

// ─── Terminal types ───────────────────────────────────────────────────────────

type TerminalLine =
  | { kind: "feed"; type: string; text: string; timestamp: string; _key: string }
  | { kind: "command"; text: string; _key: string };

const TERM_BORDER: Record<string, string> = {
  hint: "border-l-amber-500",
  "item-received": "border-l-teal-500",
  "location-checked": "border-l-blue-500",
  system: "border-l-border",
  chat: "border-l-foreground/30",
  error: "border-l-danger",
};

const TERM_LABEL: Record<string, string> = {
  hint: "INDICE",
  "item-received": "OBJET",
  "location-checked": "LOC",
  system: "SYS",
  chat: "CHAT",
  error: "ERR",
};

const TERM_COLOR: Record<string, string> = {
  hint: "text-amber-400",
  "item-received": "text-teal-400",
  "location-checked": "text-blue-400",
  system: "text-foreground/60",
  chat: "text-foreground/80",
  error: "text-danger",
};

function AdminTerminal({
  runId,
  sessionId,
  canSendCommands,
  slotNames,
}: {
  runId: string;
  sessionId: string;
  canSendCommands: boolean;
  slotNames: string[];
}) {
  const [lines, setLines] = useState<TerminalLine[]>([]);
  const [connected, setConnected] = useState(false);
  const [feedReady, setFeedReady] = useState(false);
  const [command, setCommand] = useState("");
  const [sending, setSending] = useState(false);
  const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
  const [selectedIdx, setSelectedIdx] = useState(-1);
  const scrollRef = useRef<HTMLDivElement>(null);
  const atBottomRef = useRef(true);
  const esRef = useRef<EventSource | null>(null);
  const reconnectTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Track whether user is scrolled to bottom
  useEffect(() => {
    const el = scrollRef.current;
    if (!el) return;
    function onScroll() {
      if (!el) return;
      atBottomRef.current = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    }
    el.addEventListener("scroll", onScroll, { passive: true });
    return () => { el.removeEventListener("scroll", onScroll); };
  }, [feedReady]);

  // Auto-scroll when new lines arrive (only if already at bottom)
  useEffect(() => {
    if (atBottomRef.current && scrollRef.current) {
      scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
    }
  }, [lines]);

  useEffect(() => {
    let cancelled = false;

    function connect(token: string, hubUrl: string, topic: string): void {
      if (cancelled) return;
      const url = new URL(hubUrl);
      url.searchParams.set("topic", topic);
      url.searchParams.set("authorization", token);
      const es = new EventSource(url.toString());
      esRef.current = es;

      es.onopen = () => { setConnected(true); };

      es.onmessage = (event) => {
        // Any frame - even one the guard will drop - proves the stream is alive (pre-33.19 parity).
        setConnected(true);
        const frame: unknown = event.data;
        if (typeof frame !== "string") return;
        let parsed: unknown;
        try {
          parsed = JSON.parse(frame);
        } catch {
          return; /* malformed frame - ignored */
        }
        if (!isFeedEvent(parsed)) return; /* unexpected shape - dropped, never cast */
        setLines((prev) => [
          ...prev,
          { kind: "feed" as const, type: parsed.type, text: parsed.text ?? "", timestamp: parsed.timestamp, _key: `${Date.now()}-${Math.random()}` },
        ].slice(-300));
      };

      es.onerror = () => {
        es.close();
        esRef.current = null;
        setConnected(false);
        if (!cancelled) {
          reconnectTimerRef.current = setTimeout(() => {
            reconnectWithFreshToken(`/sessions/${runId}/feed-token`, { token, hubUrl, topic }, connect, () => cancelled);
          }, 5_000);
        }
      };
    }

    async function init(): Promise<void> {
      const payload = await fetchSubscribeToken(`/sessions/${runId}/feed-token`);
      if (cancelled) return;
      if (!payload || !payload.hubUrl) { setFeedReady(true); return; }
      setFeedReady(true);
      connect(payload.token, payload.hubUrl, payload.topic);
    }

    void init().catch(() => { if (!cancelled) setFeedReady(true); });

    return () => {
      cancelled = true;
      esRef.current?.close();
      esRef.current = null;
      if (reconnectTimerRef.current) clearTimeout(reconnectTimerRef.current);
    };
  }, [runId]);

  // Recompute suggestions on every command change
  useEffect(() => {
    let cancelled = false;
    queueMicrotask(() => {
      if (cancelled) return;
      setSuggestions(getSuggestions(command, slotNames));
      setSelectedIdx(-1);
    });
    return () => {
      cancelled = true;
    };
  }, [command, slotNames]);

  function addLocalLine(type: string, text: string) {
    atBottomRef.current = true;
    setLines((prev) => [
      ...prev,
      { kind: "feed" as const, type, text, timestamp: new Date().toISOString(), _key: `local-${Date.now()}` },
    ].slice(-300));
  }

  async function sendCommand() {
    const cmd = command.trim();
    if (!cmd) return;
    setSuggestions([]);
    setSending(true);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/sessions/${sessionId}/commands`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ command: cmd }),
      });
      atBottomRef.current = true;
      if (res.ok) {
        setLines((prev) => [
          ...prev,
          { kind: "command" as const, text: cmd, _key: `cmd-${Date.now()}` },
        ].slice(-300));
        setCommand("");
      } else {
        const body = await res.json().catch(() => ({})) as { error?: string; message?: string };
        const msg = body?.message ?? body?.error ?? `Erreur ${res.status}`;
        addLocalLine("error", msg);
      }
    } catch {
      addLocalLine("error", "Impossible de joindre le serveur");
    } finally {
      setSending(false);
    }
  }

  function applySuggestion(text: string) {
    // Append a space after command-only suggestions so the user can type args
    const isCommandOnly = ARCHIPELAGO_COMMANDS.some((c) => c.name === text);
    setCommand(isCommandOnly ? `${text} ` : text);
    setSuggestions([]);
    setSelectedIdx(-1);
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (suggestions.length > 0) {
      if (e.key === "ArrowDown") {
        e.preventDefault();
        setSelectedIdx((i) => (i + 1) % suggestions.length);
        return;
      }
      if (e.key === "ArrowUp") {
        e.preventDefault();
        setSelectedIdx((i) => (i <= 0 ? suggestions.length - 1 : i - 1));
        return;
      }
      if (e.key === "Tab" || (e.key === "Enter" && selectedIdx >= 0)) {
        e.preventDefault();
        const pick = selectedIdx >= 0 ? suggestions[selectedIdx] : suggestions[0];
        if (pick) applySuggestion(pick.text);
        return;
      }
      if (e.key === "Escape") {
        setSuggestions([]);
        setSelectedIdx(-1);
        return;
      }
    }
    if (e.key === "Enter") {
      void sendCommand();
    }
  }

  return (
    <div className="overflow-hidden rounded border border-border">
      {/* Header */}
      <div className="flex items-center justify-between gap-3 border-b border-border bg-surface px-4 py-2">
        <h3 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground">
          <Terminal aria-hidden="true" className="size-4 text-success/80" />
          Terminal
        </h3>
        {connected ? (
          <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-success">
            <span className="size-1.5 animate-pulse rounded-full bg-success" aria-hidden="true" />
            LIVE
          </span>
        ) : feedReady ? (
          <span className="inline-flex items-center gap-1.5 text-xs text-accent-warm">
            <WifiOff aria-hidden="true" className="size-3" />
            Reconnexion…
          </span>
        ) : null}
      </div>

      {/* Messages */}
      <div
        className="h-80 overflow-y-auto bg-[var(--color-bg)] font-mono text-xs"
        ref={scrollRef}
      >
        {lines.length === 0 ? (
          <p className="p-4 text-muted-foreground/60">En attente de messages…</p>
        ) : (
          lines.map((line) =>
            line.kind === "command" ? (
              <div
                className="flex gap-2 border-l-4 border-l-success/40 px-3 py-1"
                key={line._key}
              >
                <span className="shrink-0 select-none text-success/50">&gt;</span>
                <span className="text-success/80">{line.text}</span>
              </div>
            ) : (
              <TermFeedLine
                key={line._key}
                text={line.text}
                timestamp={line.timestamp}
                type={line.type}
              />
            )
          )
        )}
      </div>

      {/* Command input + autocomplete */}
      {canSendCommands ? (
        <div className="relative border-t border-border bg-[var(--color-bg)]">
          {/* Suggestions - absolute overlay above input, does not affect box height */}
          {suggestions.length > 0 ? (
            <div className="absolute bottom-full left-0 right-0 z-20 border border-border bg-[var(--color-bg)] shadow-[0_-4px_16px_rgba(0,0,0,0.4)]">
              {suggestions.map((s, i) => (
                <button
                  className={`flex w-full items-baseline gap-3 px-3 py-1.5 text-left font-mono text-xs transition-colors ${i === selectedIdx ? "bg-accent-text/25 outline-none" : "hover:bg-surface/40"}`}
                  key={s.text}
                  onClick={() => { applySuggestion(s.text); }}
                  type="button"
                >
                  <span className={`shrink-0 font-semibold ${i === selectedIdx ? "text-white" : "text-success/80"}`}>{s.text}</span>
                  {s.description ? (
                    <span className={`truncate text-xs ${i === selectedIdx ? "text-white/60" : "text-muted-foreground/50"}`}>{s.description}</span>
                  ) : null}
                </button>
              ))}
            </div>
          ) : getSignatureHint(command) ? (
            <div className="absolute bottom-full left-0 right-0 z-20 border-t border-border bg-[var(--color-bg)] px-3 py-1 font-mono text-xs text-muted-foreground/40 select-none">
              {getSignatureHint(command)}
            </div>
          ) : null}

          {/* Input row */}
          <div className="flex items-center gap-2 px-3 py-2">
            <span className="shrink-0 select-none font-mono text-sm text-success/50">$</span>
            <input
              autoFocus
              className="flex-1 bg-transparent font-mono text-sm text-success/90 placeholder:text-success/25 focus:outline-none"
              disabled={sending}
              onChange={(e) => { setCommand(e.target.value); }}
              onKeyDown={handleKeyDown}
              placeholder="!players  !admin /hint Slot Item  !admin /kick Slot"
              type="text"
              value={command}
            />
            <button
              className="inline-flex items-center gap-1.5 rounded border border-success/30 bg-success/10 px-3 py-1 text-xs font-semibold text-success hover:bg-success/20 disabled:cursor-not-allowed disabled:opacity-60"
              disabled={sending || !command.trim()}
              onClick={() => { void sendCommand(); }}
              type="button"
            >
              {sending
                ? <Loader2 aria-hidden="true" className="size-3 animate-spin" />
                : <Send aria-hidden="true" className="size-3" />}
              Envoyer
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function TermFeedLine({ type, text, timestamp }: { type: string; text: string; timestamp: string }) {
  const borderCls = TERM_BORDER[type] ?? "border-l-border";
  const labelCls = TERM_COLOR[type] ?? "text-muted-foreground";
  const label = (TERM_LABEL[type] ?? type.toUpperCase()).padEnd(6);
  let time = "";
  try {
    time = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", second: "2-digit" }).format(new Date(timestamp));
  } catch { /* ignore */ }

  return (
    <div className={`flex gap-2 border-l-4 px-3 py-1 ${borderCls}`}>
      <span className="shrink-0 select-none text-muted-foreground/40">{time}</span>
      <span className={`w-14 shrink-0 font-semibold ${labelCls}`}>{label}</span>
      <span className="text-foreground/70">{text}</span>
    </div>
  );
}

// ─── LogPanel ────────────────────────────────────────────────────────────────

// Story 17.33: the containers the orchestrator creates for a session, read through it.
const LOG_CONTAINERS: { id: SessionContainer; label: string }[] = [
  { id: "ap", label: "Serveur AP" },
  { id: "bridge", label: "Bridge" },
  { id: "gen", label: "Génération" },
];

function LogPanel({ sessionId, active }: { sessionId: string; active: boolean }) {
  const [container, setContainer] = useState<SessionContainer>("ap");
  const [launching, setLaunching] = useState(false);
  const [launchResult, setLaunchResult] = useState<ForceLaunchResult | null>(null);
  const preRef = useRef<HTMLPreElement>(null);

  // 5 s poll while the session runs; a stopped session's containers do not write anymore.
  const logsQuery = useQuery({
    queryKey: ["admin-session-logs", sessionId, container],
    queryFn: () => fetchContainerLogs(sessionId, container),
    staleTime: REALTIME_STALE_TIME,
    retry: false,
    refetchInterval: active ? 5_000 : false,
  });
  const result = logsQuery.data;
  const logs = result?.kind === "ok" ? result.data.logs : "";

  async function forceLaunch() {
    setLaunching(true);
    setLaunchResult(null);
    setLaunchResult(await forceLaunchSession(sessionId));
    setLaunching(false);
  }

  useEffect(() => {
    if (preRef.current) preRef.current.scrollTop = preRef.current.scrollHeight;
  }, [logs]);

  let body: string;
  if (result === undefined) body = "";
  else if (result.kind === "unavailable") body = "L'orchestrateur ne donne pas les logs (injoignable, ou trop ancien pour cette fonction).";
  else if (result.kind === "error") body = "Impossible de charger les logs.";
  else if (!result.data.found) body = `Le conteneur ${result.data.container} n'existe pas (pas encore lancé, ou supprimé depuis).`;
  else body = result.data.logs || "Aucun log pour l'instant.";

  return (
    <div className="grid gap-4">
      <div className="overflow-hidden rounded border border-border">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-surface px-4 py-2">
          <h3 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground">
            <Terminal aria-hidden="true" className="size-4 text-success/80" />
            Logs
            {active ? <span className="animate-pulse text-xs font-semibold text-success">LIVE</span> : null}
          </h3>
          <div className="flex flex-wrap items-center gap-2">
            <div className="flex overflow-hidden rounded border border-border" role="group" aria-label="Conteneur">
              {LOG_CONTAINERS.map((c) => (
                <button
                  aria-pressed={container === c.id}
                  className={`px-3 py-1 text-xs font-medium ${container === c.id ? "bg-[var(--color-accent-text)]/15 text-[var(--color-accent-text)]" : "bg-surface text-muted-foreground hover:bg-surface-2 hover:text-foreground"}`}
                  key={c.id}
                  onClick={() => { setContainer(c.id); }}
                  type="button"
                >
                  {c.label}
                </button>
              ))}
            </div>
            <button
              className="flex items-center gap-1.5 rounded border border-border bg-surface px-3 py-1 text-xs font-medium text-muted-foreground hover:bg-surface-2 hover:text-foreground disabled:opacity-50"
              disabled={logsQuery.isFetching}
              onClick={() => { void logsQuery.refetch(); }}
              type="button"
            >
              <RefreshCw aria-hidden="true" className={`size-3.5 ${logsQuery.isFetching ? "animate-spin" : ""}`} />
              Rafraîchir
            </button>
          </div>
        </div>

        <div className="bg-[var(--color-bg)] p-4">
          {logsQuery.isPending ? (
            <div aria-hidden="true" className="grid gap-1.5">
              {[0, 1, 2].map((i) => (
                <div className="h-3 animate-pulse rounded bg-surface-2" key={i} style={{ width: `${70 + i * 10}%` }} />
              ))}
            </div>
          ) : (
            <pre
              className={`h-96 overflow-y-auto whitespace-pre-wrap font-mono text-xs ${result?.kind === "ok" && result.data.found ? "text-success/80" : "text-muted-foreground"}`}
              ref={preRef}
            >
              {body}
            </pre>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <button
          className="flex items-center gap-1.5 rounded border border-[var(--color-accent-text)]/40 bg-[var(--color-accent-text)]/10 px-3 py-1.5 text-xs font-medium text-[var(--color-accent-text)] hover:bg-[var(--color-accent-text)]/20 disabled:opacity-50"
          disabled={launching}
          onClick={() => { void forceLaunch(); }}
          type="button"
        >
          {launching ? <Loader2 aria-hidden="true" className="size-3.5 animate-spin" /> : <Zap aria-hidden="true" className="size-3.5" />}
          Forcer le lancement
        </button>
        {launchResult ? (
          <span className={`font-mono text-xs ${launchResult.success ? "text-success/80" : "text-[var(--color-danger)]/80"}`}>
            {launchResult.output}
          </span>
        ) : null}
      </div>
    </div>
  );
}

// ─── ForceEndDialog ───────────────────────────────────────────────────────────

function ForceEndDialog({
  onConfirm,
  onCancel,
}: {
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const [confirmInput, setConfirmInput] = useState("");
  const canConfirm = confirmInput.toLowerCase() === "fin";

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
      <div className="mx-4 w-full max-w-md rounded border border-danger/40 bg-surface p-6 shadow-xl">
        <div className="flex items-start gap-3">
          <AlertTriangle aria-hidden="true" className="mt-0.5 size-6 shrink-0 text-danger" />
          <div>
            <h3 className="font-heading text-lg font-semibold text-foreground">
              Forcer la fin de la run ?
            </h3>
            <p className="mt-2 text-sm text-muted-foreground">
              Cette action est irréversible. La session passera en statut{" "}
              <span className="font-semibold text-foreground">terminé</span>, le container
              sera arrêté et l&apos;archivage sera déclenché.
            </p>
          </div>
        </div>
        <div className="mt-4">
          <input
            autoFocus
            className="w-full rounded border border-border bg-background px-3 py-2 font-mono text-sm text-foreground placeholder:text-muted-foreground focus:border-danger focus:outline-none"
            onChange={(e) => { setConfirmInput(e.target.value); }}
            placeholder="Tapez FIN pour confirmer"
            type="text"
            value={confirmInput}
          />
        </div>
        <div className="mt-6 flex justify-end gap-3">
          <button
            className="inline-flex min-h-9 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground hover:border-accent"
            onClick={onCancel}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex min-h-9 items-center justify-center gap-2 rounded border border-danger/40 bg-danger/10 px-4 text-sm font-semibold text-danger hover:bg-danger/20 disabled:cursor-not-allowed disabled:opacity-40"
            disabled={!canConfirm}
            onClick={onConfirm}
            type="button"
          >
            <Flag aria-hidden="true" className="size-4" />
            Forcer la fin
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── StatusBadge ─────────────────────────────────────────────────────────────

const STATUS_LABELS: Record<SessionStatus, string> = {
  draft: "Brouillon",
  validating: "Validation",
  ready: "Prêt",
  generating: "Génération",
  generated: "Généré",
  launching: "Lancement",
  running: "En cours",
  idle: "En pause",
  restarting: "Redémarrage…",
  stopped: "Arrêté",
  failed: "Échec",
  crashed: "Planté",
  finished: "Terminé",
};

const STATUS_CLASSES: Record<SessionStatus, string> = {
  draft: "border-border bg-background text-muted-foreground",
  validating: "border-accent-warm/40 bg-accent-warm/10 text-accent-warm",
  ready: "border-accent/40 bg-accent/10 text-accent-text",
  generating: "border-accent-warm/40 bg-accent-warm/10 text-accent-warm",
  generated: "border-accent/40 bg-accent/10 text-accent-text",
  launching: "border-accent-warm/40 bg-accent-warm/10 text-accent-warm",
  running: "border-success/40 bg-success/10 text-success",
  idle: "border-[var(--color-accent-warm)]/40 bg-[var(--color-accent-warm)]/10 text-[var(--color-accent-warm)]",
  restarting: "border-[var(--color-accent-warm)]/40 bg-[var(--color-accent-warm)]/10 text-[var(--color-accent-warm)]",
  stopped: "border-border bg-background text-muted-foreground",
  failed: "border-danger/40 bg-danger/10 text-danger",
  crashed: "border-danger/40 bg-danger/10 text-danger",
  finished: "border-accent/40 bg-accent/10 text-accent-text",
};

function BouncingDots() {
  return (
    <span aria-hidden="true" className="inline-flex items-end gap-[3px] pb-px">
      <span className="inline-block size-1 animate-dot-bounce rounded-full bg-current [animation-delay:0ms]" />
      <span className="inline-block size-1 animate-dot-bounce rounded-full bg-current [animation-delay:150ms]" />
      <span className="inline-block size-1 animate-dot-bounce rounded-full bg-current [animation-delay:300ms]" />
    </span>
  );
}

const PROCESSING_STATUSES = new Set<SessionStatus>(["validating", "generating", "launching", "restarting"]);

function StatusBadge({ status }: { status: SessionStatus }) {
  return (
    <span
      className={`inline-flex items-center gap-1 rounded border px-2 py-0.5 text-xs font-semibold ${STATUS_CLASSES[status] ?? "border-border bg-background text-muted-foreground"}`}
    >
      {STATUS_LABELS[status] ?? status}
      {PROCESSING_STATUSES.has(status) && <BouncingDots />}
    </span>
  );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatInactivity(lastActivityAt: string): string {
  const delta = Date.now() - new Date(lastActivityAt).getTime();
  const totalMin = Math.floor(delta / 60_000);
  const hours = Math.floor(totalMin / 60);
  const minutes = totalMin % 60;
  return hours > 0 ? `${hours}h ${minutes}min` : `${minutes}min`;
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(new Date(value));
}

/**
 * First and last launch (story 32.24): the recap counts from the first, the uptime from the last. The last
 * launch is only shown when a relaunch moved it.
 */
function launchSummary(session: Session): string {
  if (!session.startedAt) return "";
  const first = session.firstLaunchedAt ?? session.startedAt;
  const parts = [`Premier lancement ${formatDate(first)}`];
  if (first !== session.startedAt) parts.push(`Dernier lancement ${formatDate(session.startedAt)}`);
  const duration = formatDuration(session);
  if (duration) parts.push(duration);
  return ` · ${parts.join(" · ")}`;
}

function formatDuration(session: Session): string | null {
  const elapsed = sessionUptimeMs(session, Date.now());
  if (elapsed === null) return null;
  const minutes = Math.floor(elapsed / 60000);
  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;

  if (hours > 0) {
    return `${hours} h ${remainingMinutes} min`;
  }

  return `${remainingMinutes} min`;
}
