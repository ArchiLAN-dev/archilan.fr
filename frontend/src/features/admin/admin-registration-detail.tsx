"use client";

import { AlertTriangle, CheckCircle2, ChevronLeft, Clock, CreditCard, Lock, Mail, RefreshCw, Shield, ShieldAlert, Trash2, XCircle } from "lucide-react";
import Link from "next/link";
import { use, useEffect, useId, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ─── Types ───────────────────────────────────────────────────────────────────

type OptionDetail = {
  key: string;
  label: string;
  inputType: string;
  required: boolean;
  isVisible: boolean;
  currentValue: boolean | number | string | null;
  isComplete: boolean;
};

type GameDetail = {
  slotId: string;
  slotOrder: number;
  gameId: string;
  gameName: string;
  isComplete: boolean;
  warnings: string[];
  options: OptionDetail[];
};

type PaymentDetail = {
  status: string;
  amountCents: number;
  syncedAt: string;
  isStale: boolean;
};

type RegistrationDetail = {
  registrationId: string;
  status: "reserved" | "cancelled";
  usedPrivateAccess: boolean;
  createdAt: string;
  submittedAt: string | null;
  participant: {
    userId: string;
    displayName: string | null;
    email: string;
  };
  gameSelectionComplete: boolean;
  games: GameDetail[];
  payment: PaymentDetail | null;
};

type DetailState =
  | { kind: "loading" }
  | { kind: "ready"; detail: RegistrationDetail }
  | { kind: "not_found" }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

type CancelState = "idle" | "confirming" | "cancelling" | "cancelled" | "error";

type MessageState = "idle" | "sending" | "sent" | "error";

type SyncState = "idle" | "syncing" | "done" | "error";

type SyncLogEntry = {
  attemptAt: string;
  success: boolean;
  errorMessage: string | null;
};

type SyncStatus = {
  formSlug: string | null;
  recentSyncs: SyncLogEntry[];
};

// ─── Component ───────────────────────────────────────────────────────────────

export function AdminRegistrationDetail({
  params,
}: {
  params: Promise<{ eventId: string; registrationId: string }>;
}) {
  const { eventId, registrationId } = use(params);
  const [state, setState] = useState<DetailState>({ kind: "loading" });
  const [cancelState, setCancelState] = useState<CancelState>("idle");
  const [messageState, setMessageState] = useState<MessageState>("idle");
  const [syncState, setSyncState] = useState<SyncState>("idle");

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const response = await apiFetch(
          `${env.apiBaseUrl}/admin/events/${eventId}/registrations/${registrationId}`,
        );

        if (cancelled) return;

        if (response.status === 401 || response.status === 403) {
          setState({ kind: "denied", message: "Accès réservé aux admins ArchiLAN." });
          return;
        }

        if (response.status === 404) {
          setState({ kind: "not_found" });
          return;
        }

        if (!response.ok) {
          setState({ kind: "error", message: "Impossible de charger le détail." });
          return;
        }

        const payload: unknown = await response.json();

        if (!isDetailPayload(payload)) {
          setState({ kind: "error", message: "Réponse API invalide." });
          return;
        }

        setState({ kind: "ready", detail: payload.data });
      } catch {
        if (!cancelled) {
          setState({ kind: "error", message: "Impossible de contacter l'API." });
        }
      }
    }

    void load();

    return () => {
      cancelled = true;
    };
  }, [eventId, registrationId]);

  async function handleCancelConfirmed() {
    setCancelState("cancelling");
    try {
      const response = await apiFetch(
        `${env.apiBaseUrl}/admin/events/${eventId}/registrations/${registrationId}`,
        { method: "DELETE" },
      );

      if (response.ok) {
        setCancelState("cancelled");
        if (state.kind === "ready") {
          setState({ kind: "ready", detail: { ...state.detail, status: "cancelled" } });
        }
      } else {
        setCancelState("error");
      }
    } catch {
      setCancelState("error");
    }
  }

  async function handleTriggerSync() {
    setSyncState("syncing");
    try {
      const response = await apiFetch(
        `${env.apiBaseUrl}/admin/events/${eventId}/payments/sync`,
        { method: "POST" },
      );
      setSyncState(response.ok ? "done" : "error");
    } catch {
      setSyncState("error");
    }
  }

  async function handleSendMessage(subject: string, body: string) {
    setMessageState("sending");
    try {
      const response = await apiFetch(
        `${env.apiBaseUrl}/admin/events/${eventId}/registrations/${registrationId}/messages`,
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ subject, body }),
        },
      );
      setMessageState(response.ok ? "sent" : "error");
    } catch {
      setMessageState("error");
    }
  }

  return (
    <section className="mx-auto grid w-full max-w-5xl gap-8 px-4 py-10">
      <nav>
        <Link
          className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
          href={`/admin/evenements/${eventId}/inscriptions`}
        >
          <ChevronLeft aria-hidden="true" className="size-4" />
          Retour aux inscriptions
        </Link>
      </nav>

      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Backoffice
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
          Détail d&apos;inscription
        </h1>
      </header>

      <DetailBody
        cancelState={cancelState}
        eventId={eventId}
        messageState={messageState}
        syncState={syncState}
        onCancelConfirmed={handleCancelConfirmed}
        onCancelRequested={() => { setCancelState("confirming"); }}
        onCancelDismissed={() => { setCancelState("idle"); }}
        onSendMessage={handleSendMessage}
        onMessageReset={() => { setMessageState("idle"); }}
        onTriggerSync={handleTriggerSync}
        state={state}
      />
    </section>
  );
}

// ─── DetailBody ───────────────────────────────────────────────────────────────

function DetailBody({
  cancelState,
  eventId,
  messageState,
  syncState,
  onCancelConfirmed,
  onCancelDismissed,
  onCancelRequested,
  onSendMessage,
  onMessageReset,
  onTriggerSync,
  state,
}: {
  cancelState: CancelState;
  eventId: string;
  messageState: MessageState;
  syncState: SyncState;
  onCancelConfirmed: () => Promise<void>;
  onCancelDismissed: () => void;
  onCancelRequested: () => void;
  onSendMessage: (subject: string, body: string) => Promise<void>;
  onMessageReset: () => void;
  onTriggerSync: () => Promise<void>;
  state: DetailState;
}) {
  if (state.kind === "loading") {
    return <p className="text-muted-foreground">Chargement...</p>;
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
        <h2 className="font-heading text-2xl font-semibold text-foreground">Inscription introuvable</h2>
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

  const { detail } = state;

  return (
    <div className="grid gap-6">
      <ParticipantCard
        cancelState={cancelState}
        detail={detail}
        onCancelRequested={onCancelRequested}
      />
      {cancelState === "confirming" ? (
        <CancelDialog
          onCancel={onCancelDismissed}
          onConfirm={onCancelConfirmed}
        />
      ) : null}
      {cancelState === "cancelled" ? (
        <p className="border border-success/50 bg-surface p-3 text-sm text-success" role="status">
          Inscription annulée avec succès.
        </p>
      ) : null}
      {cancelState === "error" ? (
        <p className="border border-danger/50 bg-surface p-3 text-sm text-danger" role="alert">
          Impossible d&apos;annuler l&apos;inscription. Réessaie.
        </p>
      ) : null}
      <PaymentCard eventId={eventId} payment={detail.payment} syncState={syncState} onTriggerSync={onTriggerSync} />
      <MessageForm messageState={messageState} onReset={onMessageReset} onSubmit={onSendMessage} />
      {detail.games.length > 0 ? (
        <div className="grid gap-4">
          <div className="flex items-center gap-3">
            <h2 className="font-heading text-xl font-semibold text-foreground">Jeux sélectionnés</h2>
            {detail.gameSelectionComplete ? (
              <span className="inline-flex items-center gap-1 rounded border border-success/40 bg-success/10 px-2 py-0.5 text-xs font-semibold text-success">
                <CheckCircle2 aria-hidden="true" className="size-3" />
                Complet
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 rounded border border-danger/40 bg-danger/10 px-2 py-0.5 text-xs font-semibold text-danger">
                <AlertTriangle aria-hidden="true" className="size-3" />
                Incomplet
              </span>
            )}
          </div>
          {detail.games.map((game) => (
            <GameCard game={game} key={game.slotId} />
          ))}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">Aucun jeu sélectionné.</p>
      )}
    </div>
  );
}

// ─── ParticipantCard ──────────────────────────────────────────────────────────

function ParticipantCard({
  cancelState,
  detail,
  onCancelRequested,
}: {
  cancelState: CancelState;
  detail: RegistrationDetail;
  onCancelRequested: () => void;
}) {
  return (
    <div className="grid gap-4 border border-border bg-surface p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-heading text-lg font-semibold text-foreground">
            {detail.participant.displayName ?? <span className="font-normal text-muted-foreground">(sans pseudonyme)</span>}
          </p>
          <p className="mt-0.5 text-sm text-muted-foreground">{detail.participant.email}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={detail.status} />
          {detail.usedPrivateAccess ? (
            <span className="inline-flex items-center gap-1 rounded border border-accent-warm/40 bg-accent-warm/10 px-2 py-0.5 text-xs font-semibold text-accent-warm">
              <Lock aria-hidden="true" className="size-3" />
              Accès privé
            </span>
          ) : null}
        </div>
      </div>
      <dl className="grid gap-2 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-muted-foreground">Inscrit le</dt>
          <dd className="font-medium text-foreground">
            <time dateTime={detail.createdAt}>{formatDate(detail.createdAt)}</time>
          </dd>
        </div>
        <div>
          <dt className="text-muted-foreground">Confirmation soumise</dt>
          <dd className="font-medium text-foreground">
            {detail.submittedAt ? (
              <time dateTime={detail.submittedAt}>{formatDate(detail.submittedAt)}</time>
            ) : (
              <span className="text-muted-foreground">-</span>
            )}
          </dd>
        </div>
      </dl>
      {detail.status === "reserved" && cancelState === "idle" ? (
        <div className="border-t border-border pt-4">
          <button
            className="inline-flex min-h-9 items-center justify-center gap-2 rounded border border-danger/40 px-3 text-sm font-semibold text-danger hover:border-danger hover:bg-danger/5"
            onClick={onCancelRequested}
            type="button"
          >
            <Trash2 aria-hidden="true" className="size-4" />
            Annuler l&apos;inscription
          </button>
        </div>
      ) : null}
    </div>
  );
}

// ─── CancelDialog ────────────────────────────────────────────────────────────

function CancelDialog({
  onCancel,
  onConfirm,
}: {
  onCancel: () => void;
  onConfirm: () => Promise<void>;
}) {
  const titleId = useId();
  const [submitting, setSubmitting] = useState(false);

  async function confirm() {
    setSubmitting(true);
    await onConfirm();
    setSubmitting(false);
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div
        aria-labelledby={titleId}
        aria-modal="true"
        className="w-full max-w-md border border-border bg-surface p-6 shadow-xl"
        role="alertdialog"
      >
        <h2 className="font-heading text-xl font-semibold text-foreground" id={titleId}>
          Confirmer l&apos;annulation
        </h2>
        <p className="mt-3 text-sm text-muted-foreground">
          Cette action annulera l&apos;inscription du participant et libérera sa place. Elle ne peut pas être annulée depuis cette interface.
        </p>
        <div className="mt-6 flex justify-end gap-3">
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-border px-3 text-sm font-semibold text-foreground hover:border-accent"
            disabled={submitting}
            onClick={onCancel}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-danger bg-danger px-3 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
            disabled={submitting}
            onClick={() => { void confirm(); }}
            type="button"
          >
            {submitting ? "Annulation..." : "Confirmer l'annulation"}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── PaymentCard ─────────────────────────────────────────────────────────────

function PaymentCard({
  eventId,
  payment,
  syncState,
  onTriggerSync,
}: {
  eventId: string;
  payment: PaymentDetail | null;
  syncState: SyncState;
  onTriggerSync: () => Promise<void>;
}) {
  const [syncStatus, setSyncStatus] = useState<SyncStatus | null>(null);
  const [historyOpen, setHistoryOpen] = useState(false);

  async function loadHistory() {
    if (syncStatus !== null) return;
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/payments/sync/status`);
      if (!res.ok) return;
      const payload: unknown = await res.json();
      if (isSyncStatusPayload(payload)) setSyncStatus(payload.data);
    } catch {
      // best-effort - don't block the card
    }
  }

  return (
    <div className="border border-border bg-surface p-5 sm:p-6">
      <div className="mb-4 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <CreditCard aria-hidden="true" className="size-4 text-muted-foreground" />
          <h2 className="font-heading text-lg font-semibold text-foreground">Paiement HelloAsso</h2>
        </div>
        <button
          className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-2.5 text-xs font-semibold text-muted-foreground hover:border-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-60"
          disabled={syncState === "syncing"}
          onClick={() => { void onTriggerSync(); }}
          type="button"
        >
          <RefreshCw aria-hidden="true" className={`size-3 ${syncState === "syncing" ? "animate-spin" : ""}`} />
          {syncState === "syncing" ? "Sync..." : "Lancer la sync"}
        </button>
      </div>

      {syncState === "done" ? (
        <p className="mb-3 border border-success/50 bg-success/5 p-2 text-xs text-success" role="status">
          Synchronisation déclenchée. Rechargez dans quelques secondes pour voir les données à jour.
        </p>
      ) : null}
      {syncState === "error" ? (
        <p className="mb-3 border border-danger/50 bg-danger/5 p-2 text-xs text-danger" role="alert">
          Impossible de déclencher la synchronisation.
        </p>
      ) : null}

      {null === payment ? (
        <p className="text-sm text-muted-foreground">Aucun paiement trouvé pour ce participant.</p>
      ) : (
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-muted-foreground">Statut</dt>
            <dd className="mt-0.5">
              <NormalizedPaymentStatusBadge status={payment.status} />
            </dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Montant</dt>
            <dd className="font-medium text-foreground">{formatCents(payment.amountCents)}</dd>
          </div>
          <div className="sm:col-span-2">
            <dt className="flex items-center gap-1.5 text-muted-foreground">
              <Clock aria-hidden="true" className="size-3" />
              Dernière sync
            </dt>
            <dd className="mt-0.5 flex items-center gap-2 font-medium text-foreground">
              <time dateTime={payment.syncedAt}>{formatDate(payment.syncedAt)}</time>
              {payment.isStale ? (
                <span className="inline-flex items-center gap-1 rounded border border-accent-warm/40 bg-accent-warm/10 px-1.5 py-0.5 text-xs font-semibold text-accent-warm">
                  <AlertTriangle aria-hidden="true" className="size-3" />
                  Obsolète
                </span>
              ) : null}
            </dd>
          </div>
        </dl>
      )}

      <details
        className="mt-4 border-t border-border pt-3"
        onToggle={(e) => {
          const open = (e.currentTarget as HTMLDetailsElement).open;
          setHistoryOpen(open);
          if (open) void loadHistory();
        }}
      >
        <summary className="cursor-pointer text-xs text-muted-foreground hover:text-foreground">
          Historique des synchronisations
        </summary>
        {historyOpen ? (
          <SyncHistoryPanel status={syncStatus} />
        ) : null}
      </details>
    </div>
  );
}

function SyncHistoryPanel({ status }: { status: SyncStatus | null }) {
  if (null === status) {
    return <p className="mt-2 text-xs text-muted-foreground">Chargement…</p>;
  }

  if (null === status.formSlug) {
    return <p className="mt-2 text-xs text-muted-foreground">Aucun formulaire HelloAsso configuré pour cet événement.</p>;
  }

  if (status.recentSyncs.length === 0) {
    return <p className="mt-2 text-xs text-muted-foreground">Aucune synchronisation enregistrée.</p>;
  }

  return (
    <ul className="mt-2 grid gap-1.5">
      {status.recentSyncs.map((entry, i) => (
        <li className="flex flex-wrap items-start gap-2 text-xs" key={i}>
          {entry.success ? (
            <CheckCircle2 aria-hidden="true" className="mt-px size-3 shrink-0 text-success" />
          ) : (
            <XCircle aria-hidden="true" className="mt-px size-3 shrink-0 text-danger" />
          )}
          <time className="text-muted-foreground" dateTime={entry.attemptAt}>
            {formatDate(entry.attemptAt)}
          </time>
          {!entry.success && entry.errorMessage ? (
            <span className="text-danger">{entry.errorMessage}</span>
          ) : null}
        </li>
      ))}
    </ul>
  );
}

// eslint-disable-next-line @typescript-eslint/no-unused-vars
function PaymentStatusBadge({ status }: { status: string }) {
  if (status === "processed" || status === "Processed") {
    return (
      <span className="inline-flex items-center gap-1 rounded border border-success/40 bg-success/10 px-2 py-0.5 text-xs font-semibold text-success">
        <CheckCircle2 aria-hidden="true" className="size-3" />
        Payé
      </span>
    );
  }

  if (status === "refunded" || status === "Refunded") {
    return (
      <span className="inline-flex items-center gap-1 rounded border border-danger/40 bg-danger/10 px-2 py-0.5 text-xs font-semibold text-danger">
        <XCircle aria-hidden="true" className="size-3" />
        Remboursé
      </span>
    );
  }

  return (
    <span className="inline-flex items-center gap-1 rounded border border-border bg-surface px-2 py-0.5 text-xs font-semibold text-muted-foreground">
      {status}
    </span>
  );
}

// ─── MessageForm ─────────────────────────────────────────────────────────────

function NormalizedPaymentStatusBadge({ status }: { status: string }) {
  const normalized = normalizePaymentStatus(status);

  return (
    <span className={`inline-flex items-center gap-1 rounded border px-2 py-0.5 text-xs font-semibold ${normalized.className}`}>
      {normalized.icon === "success" ? <CheckCircle2 aria-hidden="true" className="size-3" /> : null}
      {normalized.icon === "danger" ? <XCircle aria-hidden="true" className="size-3" /> : null}
      {normalized.label}
    </span>
  );
}

function normalizePaymentStatus(status: string) {
  const normalized = status.trim().toLowerCase();

  if (["processed", "confirmed", "paid", "succeeded"].includes(normalized)) {
    return { label: "Confirme", className: "border-success/40 bg-success/10 text-success", icon: "success" };
  }

  if (["pending", "authorized", "waiting", "created"].includes(normalized)) {
    return { label: "En attente", className: "border-accent-warm/40 bg-accent-warm/10 text-accent-warm", icon: "none" };
  }

  if (["failed", "refused", "canceled", "cancelled", "error"].includes(normalized)) {
    return { label: "Echec", className: "border-danger/40 bg-danger/10 text-danger", icon: "danger" };
  }

  if (["refunded", "refund"].includes(normalized)) {
    return { label: "Rembourse", className: "border-border bg-background text-muted-foreground", icon: "none" };
  }

  return { label: "Inconnu", className: "border-border bg-background text-muted-foreground", icon: "none" };
}

function MessageForm({
  messageState,
  onReset,
  onSubmit,
}: {
  messageState: MessageState;
  onReset: () => void;
  onSubmit: (subject: string, body: string) => Promise<void>;
}) {
  const subjectId = useId();
  const bodyId = useId();
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    await onSubmit(subject, body);
  }

  return (
    <div className="border border-border bg-surface p-5 sm:p-6">
      <div className="mb-4 flex items-center gap-2">
        <Mail aria-hidden="true" className="size-4 text-muted-foreground" />
        <h2 className="font-heading text-lg font-semibold text-foreground">Envoyer un message</h2>
      </div>

      {messageState === "sent" ? (
        <div className="flex items-center justify-between gap-3 border border-success/50 bg-success/5 p-3">
          <div className="flex items-center gap-2 text-sm text-success">
            <CheckCircle2 aria-hidden="true" className="size-4 shrink-0" />
            Message envoyé avec succès.
          </div>
          <button
            className="text-xs text-muted-foreground underline hover:text-foreground"
            onClick={onReset}
            type="button"
          >
            Envoyer un autre
          </button>
        </div>
      ) : (
        <form className="grid gap-4" onSubmit={(e) => { void handleSubmit(e); }}>
          <div className="grid gap-1.5">
            <label className="text-sm font-medium text-foreground" htmlFor={subjectId}>
              Sujet <span aria-hidden="true" className="text-danger">*</span>
            </label>
            <input
              className="w-full rounded border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
              disabled={messageState === "sending"}
              id={subjectId}
              onChange={(e) => { setSubject(e.target.value); }}
              placeholder="Ex. : Rappel - options de jeu à compléter"
              required
              type="text"
              value={subject}
            />
          </div>
          <div className="grid gap-1.5">
            <label className="text-sm font-medium text-foreground" htmlFor={bodyId}>
              Message <span aria-hidden="true" className="text-danger">*</span>
            </label>
            <textarea
              className="w-full rounded border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
              disabled={messageState === "sending"}
              id={bodyId}
              onChange={(e) => { setBody(e.target.value); }}
              placeholder="Corps du message..."
              required
              rows={5}
              value={body}
            />
          </div>
          {messageState === "error" ? (
            <p className="text-sm text-danger" role="alert">
              Impossible d&apos;envoyer le message. Réessaie.
            </p>
          ) : null}
          <div>
            <button
              className="inline-flex min-h-9 items-center justify-center gap-2 rounded border border-accent bg-accent px-4 text-sm font-semibold text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
              disabled={messageState === "sending"}
              type="submit"
            >
              <Mail aria-hidden="true" className="size-4" />
              {messageState === "sending" ? "Envoi..." : "Envoyer"}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

// ─── GameCard ─────────────────────────────────────────────────────────────────

function GameCard({ game }: { game: GameDetail }) {
  const visibleOptions = game.options.filter((o) => o.isVisible);
  const hiddenOptions = game.options.filter((o) => !o.isVisible);
  const statusLabel = game.warnings.length > 0
    ? "Avertissement"
    : "Options requises manquantes";

  return (
    <div className="border border-border bg-surface">
      <div className="flex items-center gap-3 border-b border-border px-5 py-3">
        {game.isComplete ? (
          <CheckCircle2 aria-hidden="true" className="size-4 text-success" />
        ) : (
          <AlertTriangle aria-hidden="true" className="size-4 text-danger" />
        )}
        <h3 className="font-heading text-base font-semibold text-foreground">{game.gameName}</h3>
        {!game.isComplete ? (
          <span className="ml-auto text-xs text-danger">{statusLabel}</span>
        ) : null}
      </div>

      {game.warnings.length > 0 ? (
        <div className="border-b border-border bg-danger/5 px-5 py-3">
          <ul className="grid gap-1.5">
            {game.warnings.map((warning) => (
              <li className="flex items-start gap-2 text-sm text-danger" key={warning}>
                <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                <span>{warning}</span>
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {visibleOptions.length > 0 ? (
        <table className="w-full border-collapse text-sm">
          <thead className="border-b border-border text-muted-foreground">
            <tr>
              <th className="px-5 py-2 text-left font-medium">Option</th>
              <th className="px-5 py-2 text-left font-medium">Valeur</th>
              <th className="px-5 py-2 text-left font-medium">Statut</th>
            </tr>
          </thead>
          <tbody>
            {visibleOptions.map((option) => (
              <tr className="border-b border-border last:border-b-0" key={option.key}>
                <td className="px-5 py-3">
                  <span className="font-medium text-foreground">{option.label}</span>
                  {option.required ? (
                    <span className="ml-1 text-xs text-danger">*</span>
                  ) : null}
                </td>
                <td className="px-5 py-3 font-mono text-sm text-foreground">
                  {option.currentValue === null ? (
                    <span className="text-muted-foreground">-</span>
                  ) : (
                    String(option.currentValue)
                  )}
                </td>
                <td className="px-5 py-3">
                  {option.isComplete ? (
                    <CheckCircle2 aria-label="Complet" className="size-4 text-success" />
                  ) : (
                    <XCircle aria-label="Incomplet" className="size-4 text-danger" />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <p className="px-5 py-4 text-sm text-muted-foreground">Aucune option visible configurée pour ce jeu.</p>
      )}

      {hiddenOptions.length > 0 ? (
        <details className="border-t border-border px-5 py-3">
          <summary className="cursor-pointer text-xs text-muted-foreground hover:text-foreground">
            {hiddenOptions.length} option{hiddenOptions.length > 1 ? "s" : ""} masquée{hiddenOptions.length > 1 ? "s" : ""}
          </summary>
          <ul className="mt-2 grid gap-1.5">
            {hiddenOptions.map((option) => (
              <li className="flex items-center gap-3 text-xs text-muted-foreground" key={option.key}>
                <span className="font-mono">{option.key}</span>
                <span>{option.label}</span>
                <span className="ml-auto font-mono">
                  {option.currentValue === null ? "-" : String(option.currentValue)}
                </span>
              </li>
            ))}
          </ul>
        </details>
      ) : null}
    </div>
  );
}

// ─── StatusBadge ─────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: RegistrationDetail["status"] }) {
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
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

function formatCents(cents: number) {
  return new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR" }).format(cents / 100);
}

function isDetailPayload(payload: unknown): payload is { data: RegistrationDetail } {
  const data =
    payload && typeof payload === "object" && "data" in payload
      ? (payload as { data: unknown }).data
      : null;

  return Boolean(
    data &&
      typeof data === "object" &&
      "registrationId" in data &&
      "status" in data &&
      "participant" in data &&
      "games" in data &&
      "payment" in data,
  );
}

function isSyncStatusPayload(payload: unknown): payload is { data: SyncStatus } {
  const data =
    payload && typeof payload === "object" && "data" in payload
      ? (payload as { data: unknown }).data
      : null;

  return Boolean(
    data &&
      typeof data === "object" &&
      "recentSyncs" in data,
  );
}
