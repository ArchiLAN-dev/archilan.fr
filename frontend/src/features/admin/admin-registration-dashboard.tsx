"use client";

import { CheckCircle2, Download, Lock, Radio, Shield, ShieldAlert, Users, XCircle } from "lucide-react";
import Link from "next/link";
import { use, useCallback, useEffect, useRef, useState } from "react";

import { env } from "@/lib/env";

// ─── Types ───────────────────────────────────────────────────────────────────

type Participant = {
  userId: string;
  displayName: string | null;
  email: string;
};

type SelectedGame = {
  gameId: string;
  gameName: string;
};

type PaymentSummary = {
  status: string;
  amountCents: number;
  syncedAt: string;
  isStale: boolean;
};

type AdminRegistration = {
  registrationId: string;
  status: "reserved" | "cancelled";
  usedPrivateAccess: boolean;
  createdAt: string;
  submittedAt: string | null;
  participant: Participant;
  selectedGames: SelectedGame[];
  gameSelectionComplete: boolean;
  payment: PaymentSummary | null;
};

type StatusFilter = "all" | "reserved" | "cancelled";

type DashboardState =
  | { kind: "loading" }
  | { kind: "ready"; registrations: AdminRegistration[]; total: number }
  | { kind: "not_found" }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

// ─── Component ───────────────────────────────────────────────────────────────

const POLLING_INTERVAL_MS = 30_000;
const DISCONNECT_GRACE_MS = 3_000;
const STALE_THRESHOLD_MS = 120_000;
const HIGHLIGHT_DURATION_MS = 3_000;

export function AdminRegistrationDashboard({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  const [state, setState] = useState<DashboardState>({ kind: "loading" });
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
  const [exporting, setExporting] = useState(false);
  const [liveConnected, setLiveConnected] = useState(false);
  const [isPollingFallback, setIsPollingFallback] = useState(false);
  const [lastLoadedAt, setLastLoadedAt] = useState<Date | null>(null);
  const [isStale, setIsStale] = useState(false);
  const [highlightedRegistrationIds, setHighlightedRegistrationIds] = useState<Set<string>>(() => new Set());
  const pollingTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const disconnectTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const load = useCallback(async (signal?: AbortSignal) => {
    try {
      const url = new URL(`${env.apiBaseUrl}/admin/events/${eventId}/registrations`);
      if (statusFilter !== "all") {
        url.searchParams.set("status", statusFilter);
      }

      const response = await fetch(url.toString(), { credentials: "include", signal });

      if (signal?.aborted) return;

      if (response.status === 401 || response.status === 403) {
        setState({ kind: "denied", message: "Accès réservé aux admins ArchiLAN." });
        return;
      }

      if (response.status === 404) {
        setState({ kind: "not_found" });
        return;
      }

      if (!response.ok) {
        setState({ kind: "error", message: "Impossible de charger les inscriptions." });
        return;
      }

      const payload: unknown = await response.json();

      if (!isPayload(payload)) {
        setState({ kind: "error", message: "Réponse API invalide." });
        return;
      }

      setState({ kind: "ready", registrations: payload.data, total: payload.meta.total });
      setLastLoadedAt(new Date());
    } catch (err) {
      if (err instanceof DOMException && err.name === "AbortError") return;
      setState({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }, [eventId, statusFilter]);

  useEffect(() => {
    const controller = new AbortController();
    void Promise.resolve().then(() => load(controller.signal));
    return () => { controller.abort(); };
  }, [load]);

  // Re-evaluate stale status whenever a load succeeds or every 30 s
  useEffect(() => {
    if (!lastLoadedAt) return;
    const resetTimer = setTimeout(() => { setIsStale(false); }, 0);
    const timer = setInterval(() => {
      setIsStale(Date.now() - lastLoadedAt.getTime() > STALE_THRESHOLD_MS);
    }, 30_000);
    return () => {
      clearTimeout(resetTimer);
      clearInterval(timer);
    };
  }, [lastLoadedAt]);

  useEffect(() => {
    if (!env.mercurePublicUrl) return;

    let cancelled = false;
    const topic = `https://archilan.fr/events/${eventId}/registrations`;
    let es: EventSource | null = null;

    function onMessage(event: MessageEvent<string>) {
      const item = parseRegistrationFeedItem(event.data);
      if (item?.type === "registration.reserved") {
        setHighlightedRegistrationIds((current) => new Set(current).add(item.registrationId));
        setTimeout(() => {
          setHighlightedRegistrationIds((current) => {
            const next = new Set(current);
            next.delete(item.registrationId);
            return next;
          });
        }, HIGHLIGHT_DURATION_MS);
      }
      void load();
    }

    function onOpen() {
      if (disconnectTimerRef.current !== null) {
        clearTimeout(disconnectTimerRef.current);
        disconnectTimerRef.current = null;
      }
      setLiveConnected(true);
      setIsPollingFallback(false);
      if (pollingTimerRef.current !== null) {
        clearInterval(pollingTimerRef.current);
        pollingTimerRef.current = null;
      }
    }

    function onError() {
      if (pollingTimerRef.current === null) {
        pollingTimerRef.current = setInterval(() => { void load(); }, POLLING_INTERVAL_MS);
      }
      setIsPollingFallback(true);
      // Defer the disconnected badge by the grace period to avoid flashing on transient blips
      if (disconnectTimerRef.current === null) {
        disconnectTimerRef.current = setTimeout(() => {
          setLiveConnected(false);
          disconnectTimerRef.current = null;
        }, DISCONNECT_GRACE_MS);
      }
    }

    async function connect() {
      try {
        const authorizeUrl = new URL(`${env.apiBaseUrl}/realtime/subscribe-token`);
        authorizeUrl.searchParams.append("topics[]", topic);
        const response = await fetch(authorizeUrl.toString(), {
          credentials: "include",
        });

        if (!response.ok || cancelled) {
          onError();
          return;
        }

        const url = new URL(env.mercurePublicUrl as string);
        url.searchParams.set("topic", topic);

        es = new EventSource(url.toString(), { withCredentials: true });
        es.addEventListener("message", onMessage);
        es.addEventListener("open", onOpen);
        es.addEventListener("error", onError);
      } catch {
        if (!cancelled) {
          onError();
        }
      }
    }

    void connect();

    return () => {
      cancelled = true;
      es?.removeEventListener("message", onMessage);
      es?.removeEventListener("open", onOpen);
      es?.removeEventListener("error", onError);
      es?.close();
      if (pollingTimerRef.current !== null) {
        clearInterval(pollingTimerRef.current);
        pollingTimerRef.current = null;
      }
      if (disconnectTimerRef.current !== null) {
        clearTimeout(disconnectTimerRef.current);
        disconnectTimerRef.current = null;
      }
      setLiveConnected(false);
      setIsPollingFallback(false);
    };
  }, [eventId, load]);

  async function exportRegistrations(includeCancelled: boolean) {
    setExporting(true);
    try {
      const url = new URL(`${env.apiBaseUrl}/admin/events/${eventId}/registrations/export`);
      if (includeCancelled) {
        url.searchParams.set("include_cancelled", "true");
      }

      const response = await fetch(url.toString(), { credentials: "include" });

      if (!response.ok) {
        return;
      }

      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = objectUrl;
      anchor.download = `registrations-${eventId}.json`;
      anchor.click();
      URL.revokeObjectURL(objectUrl);
    } catch {
      // silent - export is best-effort
    } finally {
      setExporting(false);
    }
  }

  return (
    <section className="grid w-full min-w-0 grid-cols-1 gap-8 px-4 py-10">
      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Backoffice
        </p>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
            Inscriptions
          </h1>
          {liveConnected ? (
            <span
              aria-label="Flux en direct connecté"
              className="inline-flex items-center gap-1.5 rounded border border-success/40 bg-success/10 px-2 py-0.5 text-xs font-semibold text-success"
              title="Mises à jour en direct"
            >
              <Radio aria-hidden="true" className="size-3" />
              Live
            </span>
          ) : null}
          {isStale ? (
            <span className="inline-flex items-center gap-2 rounded border border-accent-warm/40 bg-accent-warm/10 px-2 py-0.5 text-xs font-semibold text-accent-warm">
              Données peut-être obsolètes
              <button
                className="underline hover:no-underline"
                onClick={() => { void load(); }}
                type="button"
              >
                Actualiser
              </button>
            </span>
          ) : null}
          {isPollingFallback ? (
            <span className="inline-flex items-center gap-1.5 rounded border border-accent-warm/40 bg-accent-warm/10 px-2 py-0.5 text-xs font-semibold text-accent-warm">
              <Radio aria-hidden="true" className="size-3" />
              Live deconnecte, polling actif
            </span>
          ) : null}
        </div>
      </header>

      <div className="flex flex-wrap items-center gap-4">
        <label className="flex items-center gap-2 text-sm font-semibold text-foreground" htmlFor="status-filter">
          Statut
          <select
            className="min-h-9 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
            id="status-filter"
            onChange={(e) => { setStatusFilter(e.target.value as StatusFilter); }}
            value={statusFilter}
          >
            <option value="all">Tous</option>
            <option value="reserved">Réservés</option>
            <option value="cancelled">Annulés</option>
          </select>
        </label>
        {state.kind === "ready" ? (
          <span className="text-sm text-muted-foreground">
            {state.total} inscription{state.total !== 1 ? "s" : ""}
          </span>
        ) : null}
        <div className="ml-auto flex items-center gap-2">
          <button
            className="inline-flex min-h-9 items-center justify-center gap-2 rounded border border-border px-3 text-sm font-semibold text-foreground hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
            disabled={exporting}
            onClick={() => { void exportRegistrations(false); }}
            type="button"
          >
            <Download aria-hidden="true" className="size-4" />
            {exporting ? "Export..." : "Exporter (réservés)"}
          </button>
          <button
            className="inline-flex min-h-9 items-center justify-center gap-2 rounded border border-border px-3 text-sm font-semibold text-foreground hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
            disabled={exporting}
            onClick={() => { void exportRegistrations(true); }}
            type="button"
          >
            <Download aria-hidden="true" className="size-4" />
            {exporting ? "Export..." : "Exporter (tous)"}
          </button>
        </div>
      </div>

      <RegistrationList eventId={eventId} highlightedRegistrationIds={highlightedRegistrationIds} state={state} />
    </section>
  );
}

// ─── RegistrationList ─────────────────────────────────────────────────────────

function RegistrationList({
  eventId,
  highlightedRegistrationIds,
  state,
}: {
  eventId: string;
  highlightedRegistrationIds: Set<string>;
  state: DashboardState;
}) {
  if (state.kind === "loading") {
    return <p className="text-muted-foreground">Chargement des inscriptions...</p>;
  }

  if (state.kind === "denied") {
    return (
      <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
        <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">Accès admin requis</h2>
        <p className="max-w-md text-sm leading-6 text-muted-foreground">{state.message}</p>
      </div>
    );
  }

  if (state.kind === "not_found") {
    return (
      <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
        <h2 className="font-heading text-2xl font-semibold text-foreground">Événement introuvable</h2>
      </div>
    );
  }

  if (state.kind === "error") {
    return (
      <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
        <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">Erreur</h2>
        <p className="max-w-md text-sm leading-6 text-muted-foreground">{state.message}</p>
      </div>
    );
  }

  if (state.registrations.length === 0) {
    return (
      <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
        <Users aria-hidden="true" className="size-8 text-accent-text" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">Aucune inscription</h2>
        <p className="max-w-md text-sm leading-6 text-muted-foreground">
          Aucun participant n&apos;a encore rejoint cet événement.
        </p>
      </div>
    );
  }

  return (
    <div className="min-w-0 overflow-x-auto border border-border bg-surface">
      <table className="w-full min-w-[960px] border-collapse text-left text-sm">
        <thead className="border-b border-border text-muted-foreground">
          <tr>
            <th className="px-4 py-3 font-medium">Participant</th>
            <th className="px-4 py-3 font-medium">Email</th>
            <th className="px-4 py-3 font-medium">Statut</th>
            <th className="px-4 py-3 font-medium">Accès privé</th>
            <th className="px-4 py-3 font-medium">Jeux sélectionnés</th>
            <th className="px-4 py-3 font-medium">Complet</th>
            <th className="px-4 py-3 font-medium">Paiement</th>
            <th className="px-4 py-3 font-medium">Inscrit le</th>
            <th className="px-4 py-3 font-medium">Soumis le</th>
            <th className="px-4 py-3 font-medium"></th>
          </tr>
        </thead>
        <tbody>
          {state.registrations.map((r) => (
            <tr
              className={`border-b border-border last:border-b-0 ${highlightedRegistrationIds.has(r.registrationId) ? "registration-feed-highlight" : ""}`}
              key={r.registrationId}
            >
              <td className="px-4 py-4 font-semibold text-foreground">
                {r.participant.displayName ?? <span className="font-normal text-muted-foreground">(sans pseudonyme)</span>}
              </td>
              <td className="px-4 py-4 text-muted-foreground">{r.participant.email}</td>
              <td className="px-4 py-4">
                <StatusBadge status={r.status} />
              </td>
              <td className="px-4 py-4 text-center">
                {r.usedPrivateAccess ? (
                  <Lock aria-label="Accès privé utilisé" className="mx-auto size-4 text-accent-warm" />
                ) : (
                  <span className="text-muted-foreground">-</span>
                )}
              </td>
              <td className="px-4 py-4">
                {r.selectedGames.length === 0 ? (
                  <span className="text-muted-foreground">-</span>
                ) : (
                  <ul className="grid gap-0.5">
                    {r.selectedGames.map((g) => (
                      <li className="text-xs text-foreground" key={g.gameId}>{g.gameName}</li>
                    ))}
                  </ul>
                )}
              </td>
              <td className="px-4 py-4 text-center">
                {r.selectedGames.length === 0 ? (
                  <span className="text-muted-foreground">-</span>
                ) : r.gameSelectionComplete ? (
                  <CheckCircle2 aria-label="Sélection complète" className="mx-auto size-4 text-success" />
                ) : (
                  <XCircle aria-label="Sélection incomplète" className="mx-auto size-4 text-danger" />
                )}
              </td>
              <td className="px-4 py-4">
                <PaymentSummaryBadge payment={r.payment} />
              </td>
              <td className="px-4 py-4 text-muted-foreground">
                <time dateTime={r.createdAt}>{formatDate(r.createdAt)}</time>
              </td>
              <td className="px-4 py-4 text-muted-foreground">
                {r.submittedAt ? (
                  <time dateTime={r.submittedAt}>{formatDate(r.submittedAt)}</time>
                ) : (
                  <span>-</span>
                )}
              </td>
              <td className="px-4 py-4">
                <Link
                  className="inline-flex min-h-9 items-center justify-center rounded border border-border px-2.5 text-xs font-semibold text-foreground hover:border-accent"
                  href={`/admin/evenements/${eventId}/inscriptions/${r.registrationId}`}
                >
                  Voir
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ─── StatusBadge ─────────────────────────────────────────────────────────────

function PaymentSummaryBadge({ payment }: { payment: PaymentSummary | null }) {
  if (payment === null) {
    return <span className="text-xs text-muted-foreground">Non synchronise</span>;
  }

  const status = normalizePaymentStatus(payment.status);

  return (
    <div className="grid gap-1">
      <span className={`inline-flex w-fit items-center rounded border px-2 py-0.5 text-xs font-semibold ${status.className}`}>
        {status.label}
      </span>
      {payment.isStale ? (
        <span className="text-xs font-medium text-accent-warm">Donnees obsoletes</span>
      ) : null}
    </div>
  );
}

function StatusBadge({ status }: { status: AdminRegistration["status"] }) {
  if (status === "cancelled") {
    return (
      <span className="inline-flex items-center gap-1 rounded border border-danger/40 bg-danger/10 px-2 py-0.5 text-xs font-semibold text-danger">
        <XCircle aria-hidden="true" className="size-3" />
        Annulé
      </span>
    );
  }

  return (
    <span className="inline-flex items-center gap-1 rounded border border-accent/40 bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent-text">
      <Shield aria-hidden="true" className="size-3" />
      Réservé
    </span>
  );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatDate(value: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(new Date(value));
}

function normalizePaymentStatus(status: string) {
  const normalized = status.trim().toLowerCase();

  if (["processed", "confirmed", "paid", "succeeded"].includes(normalized)) {
    return { label: "Confirme", className: "border-success/40 bg-success/10 text-success" };
  }

  if (["pending", "authorized", "waiting", "created"].includes(normalized)) {
    return { label: "En attente", className: "border-accent-warm/40 bg-accent-warm/10 text-accent-warm" };
  }

  if (["failed", "refused", "canceled", "cancelled", "error"].includes(normalized)) {
    return { label: "Echec", className: "border-danger/40 bg-danger/10 text-danger" };
  }

  if (["refunded", "refund"].includes(normalized)) {
    return { label: "Rembourse", className: "border-border bg-background text-muted-foreground" };
  }

  return { label: "Inconnu", className: "border-border bg-background text-muted-foreground" };
}

function isPayload(payload: unknown): payload is { data: AdminRegistration[]; meta: { total: number } } {
  return Boolean(
    payload &&
      typeof payload === "object" &&
      "data" in payload &&
      Array.isArray((payload as { data: unknown }).data) &&
      "meta" in payload &&
      typeof (payload as { meta: unknown }).meta === "object",
  );
}

type RegistrationFeedItem = {
  type: "registration.reserved";
  registrationId: string;
  createdAt: string;
};

function parseRegistrationFeedItem(value: string): RegistrationFeedItem | null {
  try {
    const parsed: unknown = JSON.parse(value);
    if (
      typeof parsed === "object" &&
      parsed !== null &&
      "type" in parsed &&
      parsed.type === "registration.reserved" &&
      "registrationId" in parsed &&
      typeof parsed.registrationId === "string" &&
      "createdAt" in parsed &&
      typeof parsed.createdAt === "string"
    ) {
      return {
        type: "registration.reserved",
        registrationId: parsed.registrationId,
        createdAt: parsed.createdAt,
      };
    }
  } catch {
    return null;
  }

  return null;
}
