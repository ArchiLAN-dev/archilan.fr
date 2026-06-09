"use client";

import { CalendarPlus, CheckCircle2, Eye, Gamepad2, KeyRound, Pencil, Play, Plus, RotateCcw, Server, ShieldAlert, Users, Video } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import type { FormEvent } from "react";
import Link from "next/link";
import { useEffect, useId, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// Unified row-button styles: neutral for actions, accent for "state" chips
// (game selection enabled, recap attached). Same size / radius / hover everywhere.
const ROW_BTN_BASE =
  "inline-flex min-h-8 items-center justify-center gap-1.5 rounded-md border px-2.5 text-xs font-semibold transition-colors";
const ROW_BTN_NEUTRAL = `${ROW_BTN_BASE} border-border text-foreground hover:border-accent hover:text-accent-text`;
const ROW_BTN_ACTIVE = `${ROW_BTN_BASE} border-accent/60 text-accent-text hover:border-accent`;

type AdminEvent = {
  id: string;
  title: string;
  description: string;
  coverImageUrl: string | null;
  photoGallery: string[];
  status: "draft" | "published" | "in-progress" | "completed";
  startsAt: string;
  endsAt: string;
  venue: string;
  capacity: number;
  confirmedRegistrations: number;
  isAtCapacity: boolean;
  registrationOpensAt: string;
  registrationClosesAt: string;
  isPublic: boolean;
  visibility: "public" | "private";
  hasPrivateAccessPassword: boolean;
  gameSelectionEnabled: boolean;
  vodUrl: string | null;
  recapPostSlug: string | null;
  hasRecap: boolean;
  createdAt: string;
  updatedAt: string;
};

type DashboardState =
  | { kind: "loading" }
  | { kind: "ready"; events: AdminEvent[] }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

type StatusAction = { label: string; status: AdminEvent["status"] };

type PendingTransition = {
  event: AdminEvent;
  status: AdminEvent["status"];
};

type PendingPrivateAccess = {
  event: AdminEvent;
};

export function AdminEventDashboard() {
  const [state, setState] = useState<DashboardState>({ kind: "loading" });
  const [message, setMessage] = useState<string | null>(null);
  const [pendingTransition, setPendingTransition] = useState<PendingTransition | null>(null);
  const [pendingPrivateAccess, setPendingPrivateAccess] = useState<PendingPrivateAccess | null>(null);
  const [recapEvent, setRecapEvent] = useState<AdminEvent | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function loadEvents() {
      try {
        const response = await apiFetch(`${env.apiBaseUrl}/admin/events`);

        if (cancelled) return;

        if (response.status === 401 || response.status === 403) {
          setState({ kind: "denied", message: "Accès réservé aux admins ArchiLAN." });
          return;
        }

        if (!response.ok) {
          setState({ kind: "error", message: "Impossible de charger les événements." });
          return;
        }

        const payload: unknown = await response.json();
        setState({ kind: "ready", events: isEventListPayload(payload) ? payload.data : [] });
      } catch {
        if (!cancelled) {
          setState({ kind: "error", message: "Impossible de contacter l'API événements." });
        }
      }
    }

    void loadEvents();
    return () => { cancelled = true; };
  }, []);

  function requestTransition(event: AdminEvent, status: AdminEvent["status"]) {
    setPendingTransition({ event, status });
  }

  async function executeTransition() {
    if (!pendingTransition) return;

    const { event, status } = pendingTransition;
    setPendingTransition(null);

    try {
      const updated = await submitStatus(event.id, status);

      if (state.kind === "ready") {
        setState({
          kind: "ready",
          events: state.events.map((item) => (item.id === updated.id ? updated : item)),
        });
      }

      setMessage(status === "draft" ? "Événement dépublié." : "Statut mis à jour.");
    } catch {
      setMessage("Transition de statut impossible.");
    }
  }

  async function configurePrivateAccess(event: AdminEvent, password: string) {
    try {
      const updated = await submitPrivateAccess(event.id, password);

      if (state.kind === "ready") {
        setState({
          kind: "ready",
          events: state.events.map((item) => (item.id === updated.id ? updated : item)),
        });
      }

      setMessage("Accès privé configuré.");
      setPendingPrivateAccess(null);
    } catch {
      setMessage("Configuration d'accès privé impossible.");
    }
  }

  return (
    <section className="grid w-full gap-8 px-4 py-10">
      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
          <div>
            <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">Événements</h1>
            <p className="mt-3 max-w-2xl text-muted-foreground">
              Gère les événements ArchiLAN : brouillons, publications, inscriptions et sélections de jeux.
            </p>
          </div>
          <Link
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/admin/evenements/nouveau"
          >
            <Plus aria-hidden="true" className="size-4" />
            Nouvel événement
          </Link>
        </div>
      </header>

      {message ? (
        <p className="border border-success/50 bg-surface p-3 text-sm text-success" role="status">
          {message}
        </p>
      ) : null}

      <EventList
        onConfigurePrivateAccess={(event) => setPendingPrivateAccess({ event })}
        onConfigureRecap={setRecapEvent}
        onTransition={requestTransition}
        state={state}
      />

      {pendingTransition ? (
        <StatusTransitionDialog
          onCancel={() => setPendingTransition(null)}
          onConfirm={executeTransition}
          pendingTransition={pendingTransition}
        />
      ) : null}

      {pendingPrivateAccess ? (
        <PrivateAccessDialog
          event={pendingPrivateAccess.event}
          onCancel={() => setPendingPrivateAccess(null)}
          onSubmit={configurePrivateAccess}
        />
      ) : null}

      {recapEvent ? (
        <RecapDialog
          event={recapEvent}
          onClose={() => setRecapEvent(null)}
          onSaved={(vodUrl, recapPostSlug) => {
            if (state.kind === "ready") {
              setState({
                kind: "ready",
                events: state.events.map((e) =>
                  e.id === recapEvent.id
                    ? { ...e, vodUrl, recapPostSlug, hasRecap: vodUrl !== null || recapPostSlug !== null }
                    : e,
                ),
              });
            }
            setRecapEvent(null);
            setMessage("Récap mis à jour.");
          }}
        />
      ) : null}
    </section>
  );
}

async function submitStatus(eventId: string, status: AdminEvent["status"]): Promise<AdminEvent> {
  const response = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/status`, {
    body: JSON.stringify({ status }),
    headers: { "Content-Type": "application/json" },
    method: "PATCH",
  });
  const payload: unknown = await response.json();

  if (!response.ok || !isEventPayload(payload)) {
    throw new Error("Transition de statut impossible.");
  }

  return payload.data;
}

async function submitPrivateAccess(eventId: string, password: string): Promise<AdminEvent> {
  const response = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/private-access`, {
    body: JSON.stringify({ password }),
    headers: { "Content-Type": "application/json" },
    method: "PATCH",
  });
  const payload: unknown = await response.json();

  if (!response.ok || !isEventPayload(payload)) {
    throw new Error("Configuration d'accès privé impossible.");
  }

  return payload.data;
}

function StatusTransitionDialog({
  onCancel,
  onConfirm,
  pendingTransition,
}: {
  onCancel: () => void;
  onConfirm: () => void;
  pendingTransition: PendingTransition;
}) {
  const titleId = useId();

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div
        aria-labelledby={titleId}
        aria-modal="true"
        className="w-full max-w-md rounded-lg border border-border bg-surface/80 p-6 shadow-xl backdrop-blur-sm"
        role="alertdialog"
      >
        <h2 className="font-heading text-xl font-semibold text-foreground" id={titleId}>
          Confirmer la transition
        </h2>
        <p className="mt-3 text-sm text-muted-foreground">
          {transitionConfirmation(pendingTransition.event.status, pendingTransition.status)}
        </p>
        <div className="mt-6 flex justify-end gap-3">
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-border px-3 text-sm font-semibold text-foreground hover:border-accent"
            onClick={onCancel}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-3 text-sm font-semibold text-white hover:bg-accent-hover"
            onClick={onConfirm}
            type="button"
          >
            Confirmer
          </button>
        </div>
      </div>
    </div>
  );
}

function PrivateAccessDialog({
  event,
  onCancel,
  onSubmit,
}: {
  event: AdminEvent;
  onCancel: () => void;
  onSubmit: (event: AdminEvent, password: string) => Promise<void>;
}) {
  const titleId = useId();
  const errorId = useId();
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit(formEvent: FormEvent<HTMLFormElement>) {
    formEvent.preventDefault();
    setError(null);
    const form = new FormData(formEvent.currentTarget);
    const password = String(form.get("password") ?? "");

    if (password.length < 8) {
      setError("Le mot de passe doit contenir au moins 8 caractères.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit(event, password);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <form
        aria-labelledby={titleId}
        aria-modal="true"
        className="grid w-full max-w-md gap-4 rounded-lg border border-border bg-surface/80 p-6 shadow-xl backdrop-blur-sm"
        onSubmit={submit}
        role="dialog"
      >
        <div>
          <h2 className="font-heading text-xl font-semibold text-foreground" id={titleId}>
            Configurer l&apos;accès privé
          </h2>
          <p className="mt-2 text-sm text-muted-foreground">
            Le mot de passe sera haché côté API et ne sera plus affiché après enregistrement.
          </p>
        </div>
        <label className="grid gap-2 text-sm font-semibold text-foreground">
          Mot de passe privé
          <input
            aria-describedby={error ? errorId : undefined}
            aria-invalid={error ? true : undefined}
            autoComplete="new-password"
            className="min-h-11 rounded border border-border bg-background px-3 outline-none focus:border-accent"
            name="password"
            type="password"
          />
          {error ? <span className="text-xs text-danger" id={errorId}>{error}</span> : null}
        </label>
        <div className="flex justify-end gap-3">
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-border px-3 text-sm font-semibold text-foreground hover:border-accent"
            onClick={onCancel}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-3 text-sm font-semibold text-white hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
            disabled={submitting}
            type="submit"
          >
            {submitting ? "Enregistrement..." : event.hasPrivateAccessPassword ? "Changer le mot de passe" : "Enregistrer"}
          </button>
        </div>
      </form>
    </div>
  );
}

function EventList({
  onConfigurePrivateAccess,
  onConfigureRecap,
  onTransition,
  state,
}: {
  onConfigurePrivateAccess: (event: AdminEvent) => void;
  onConfigureRecap: (event: AdminEvent) => void;
  onTransition: (event: AdminEvent, status: AdminEvent["status"]) => void;
  state: DashboardState;
}) {
  if (state.kind === "loading") {
    return (
      <div className="overflow-hidden rounded-lg border border-border bg-surface">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[960px] border-collapse text-left text-sm">
            <thead className="border-b border-border text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">Titre</th>
                <th className="px-4 py-3 font-medium">Statut</th>
                <th className="whitespace-nowrap px-4 py-3 font-medium">Début</th>
                <th className="whitespace-nowrap px-4 py-3 font-medium">Capacité</th>
                <th className="px-4 py-3 font-medium">Visibilité</th>
                <th className="px-4 py-3 font-medium">Jeux</th>
                <th className="px-4 py-3 font-medium">Récap</th>
                <th className="px-4 py-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {Array.from({ length: 4 }).map((_, i) => (
                <tr className="animate-pulse border-b border-border last:border-b-0" key={i}>
                  <td className="px-4 py-3">
                    <div className="flex flex-col gap-1.5">
                      <div className="h-3.5 rounded bg-surface-2" style={{ width: `${[140, 112, 160, 128][i]}px` }} />
                      <div className="h-2.5 w-20 rounded bg-surface-2 opacity-50" />
                    </div>
                  </td>
                  <td className="px-4 py-3"><div className="h-3 w-16 rounded bg-surface-2" /></td>
                  <td className="px-4 py-3"><div className="h-3 w-24 rounded bg-surface-2" /></td>
                  <td className="px-4 py-3"><div className="h-3 w-10 rounded bg-surface-2" /></td>
                  <td className="px-4 py-3"><div className="h-3 w-14 rounded bg-surface-2" /></td>
                  <td className="px-4 py-3"><div className="h-7 w-20 rounded bg-surface-2" /></td>
                  <td className="px-4 py-3"><div className="h-3 w-4 rounded bg-surface-2" /></td>
                  <td className="px-4 py-3">
                    <div className="flex gap-1.5">
                      <div className="h-7 w-16 rounded bg-surface-2" />
                      <div className="h-7 w-14 rounded bg-surface-2" />
                      <div className="h-7 w-16 rounded bg-surface-2" />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  if (state.kind === "denied" || state.kind === "error") {
    return (
      <div className="grid justify-items-center gap-3 rounded-lg border border-border bg-surface p-8 text-center">
        <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">
          {state.kind === "denied" ? "Accès admin requis" : "Événements indisponibles"}
        </h2>
        <p className="max-w-md text-sm leading-6 text-muted-foreground">{state.message}</p>
      </div>
    );
  }

  if (state.events.length === 0) {
    return (
      <div className="grid justify-items-center gap-3 rounded-lg border border-border bg-surface p-8 text-center">
        <CalendarPlus aria-hidden="true" className="size-8 text-accent-text" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">Aucun événement</h2>
        <p className="max-w-md text-sm leading-6 text-muted-foreground">
          Crée le premier brouillon pour préparer une session ArchiLAN.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-surface">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[960px] border-collapse text-left text-sm">
          <thead className="border-b border-border text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">Titre</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="whitespace-nowrap px-4 py-3 font-medium">Début</th>
              <th className="whitespace-nowrap px-4 py-3 font-medium">Capacité</th>
              <th className="px-4 py-3 font-medium">Visibilité</th>
              <th className="px-4 py-3 font-medium">Jeux</th>
              <th className="px-4 py-3 font-medium">Récap</th>
              <th className="px-4 py-3 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody>
            {state.events.map((event) => (
              <tr className="border-b border-border last:border-b-0" key={event.id}>
                <td className="px-4 py-3 font-semibold text-foreground">
                  <span>{event.title}</span>
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-accent-text">{statusLabel(event.status)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">
                  <time dateTime={event.startsAt}>{formatDateShort(event.startsAt)}</time>
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">
                  <div className="flex items-center gap-2">
                    <span>{event.confirmedRegistrations}/{event.capacity}</span>
                    {event.isAtCapacity ? (
                      <span className="inline-flex items-center gap-1 rounded border border-danger/40 bg-danger/10 px-1.5 py-0.5 text-xs font-semibold text-danger">
                        <Users aria-hidden="true" className="size-3" />
                        Complet
                      </span>
                    ) : null}
                  </div>
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">
                  {event.isPublic ? "Public" : event.hasPrivateAccessPassword ? "Privé protégé" : "Privé"}
                </td>
                <td className="px-4 py-3">
                  <Link
                    className={event.gameSelectionEnabled ? ROW_BTN_ACTIVE : ROW_BTN_NEUTRAL}
                    href={`/admin/evenements/${event.id}/jeux`}
                    title={event.gameSelectionEnabled ? "Sélection activée" : "Configurer la sélection de jeux"}
                  >
                    <Gamepad2 aria-hidden="true" className="size-3.5" />
                    {event.gameSelectionEnabled ? "Activée" : "Configurer"}
                  </Link>
                </td>
                <td className="px-4 py-3">
                  {event.status === "completed" ? (
                    <button
                      className={event.hasRecap ? ROW_BTN_ACTIVE : ROW_BTN_NEUTRAL}
                      onClick={() => onConfigureRecap(event)}
                      title={event.hasRecap ? "Récap attaché" : "Attacher un récap ou une VOD"}
                      type="button"
                    >
                      <Video aria-hidden="true" className="size-3.5" />
                      {event.hasRecap ? "Attaché" : "Attacher"}
                    </button>
                  ) : (
                    <span className="text-xs text-muted-foreground">-</span>
                  )}
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1.5">
                    <Link className={ROW_BTN_NEUTRAL} href={`/admin/evenements/${event.id}/modifier`}>
                      <Pencil aria-hidden="true" className="size-3.5" />
                      Modifier
                    </Link>
                    <Link className={ROW_BTN_NEUTRAL} href={`/admin/evenements/${event.id}/inscriptions`}>
                      <Users aria-hidden="true" className="size-3.5" />
                      Inscrits
                    </Link>
                    {event.gameSelectionEnabled ? (
                      <Link className={ROW_BTN_NEUTRAL} href={`/admin/evenements/${event.id}/session`}>
                        <Server aria-hidden="true" className="size-3.5" />
                        Run
                      </Link>
                    ) : null}
                    {transitionActions(event.status).map((action) => {
                      const Icon = transitionIcon(action.status);
                      return (
                        <button
                          className={ROW_BTN_NEUTRAL}
                          key={action.status}
                          onClick={() => onTransition(event, action.status)}
                          type="button"
                        >
                          <Icon aria-hidden="true" className="size-3.5" />
                          {action.label}
                        </button>
                      );
                    })}
                    {!event.isPublic ? (
                      <button
                        className={ROW_BTN_NEUTRAL}
                        onClick={() => onConfigurePrivateAccess(event)}
                        type="button"
                      >
                        <KeyRound aria-hidden="true" className="size-3.5" />
                        {event.hasPrivateAccessPassword ? "Changer accès" : "Config. accès"}
                      </button>
                    ) : null}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function RecapDialog({
  event,
  onClose,
  onSaved,
}: {
  event: AdminEvent;
  onClose: () => void;
  onSaved: (vodUrl: string | null, recapPostSlug: string | null) => void;
}) {
  const titleId = useId();
  const vodUrlErrorId = useId();
  const slugErrorId = useId();
  const [vodUrlError, setVodUrlError] = useState<string | null>(null);
  const [slugError, setSlugError] = useState<string | null>(null);
  const [genericError, setGenericError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit(formEvent: FormEvent<HTMLFormElement>) {
    formEvent.preventDefault();
    setVodUrlError(null);
    setSlugError(null);
    setGenericError(null);
    setSubmitting(true);

    const form = new FormData(formEvent.currentTarget);
    const vodUrl = String(form.get("vodUrl") ?? "").trim() || null;
    const recapPostSlug = String(form.get("recapPostSlug") ?? "").trim() || null;

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/admin/events/${event.id}/recap`, {
        body: JSON.stringify({ vodUrl, recapPostSlug }),
        headers: { "Content-Type": "application/json" },
        method: "PATCH",
      });

      const payload: unknown = await response.json();

      if (!response.ok) {
        const details = extractDetails(payload);
        if (details.vodUrl) setVodUrlError(details.vodUrl);
        if (details.recapPostSlug) setSlugError(details.recapPostSlug);
        if (!details.vodUrl && !details.recapPostSlug) setGenericError("Impossible d'enregistrer le récap.");
        return;
      }

      onSaved(vodUrl, recapPostSlug);
    } catch {
      setGenericError("Impossible de contacter l'API.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <form
        aria-labelledby={titleId}
        aria-modal="true"
        className="grid w-full max-w-md gap-4 rounded-lg border border-border bg-surface/80 p-6 shadow-xl backdrop-blur-sm"
        onSubmit={submit}
        role="dialog"
      >
        <div className="flex items-center justify-between gap-3">
          <h2 className="font-heading text-xl font-semibold text-foreground" id={titleId}>
            Récap - {event.title}
          </h2>
          <button aria-label="Fermer" className="text-muted-foreground hover:text-foreground" onClick={onClose} type="button">
            ✕
          </button>
        </div>

        {genericError ? (
          <p className="text-sm text-danger" role="alert">{genericError}</p>
        ) : null}

        <label className="grid gap-2 text-sm font-semibold text-foreground">
          URL de la VOD (optionnel)
          <input
            aria-describedby={vodUrlError ? vodUrlErrorId : undefined}
            aria-invalid={vodUrlError ? true : undefined}
            className="min-h-11 rounded border border-border bg-background px-3 outline-none focus:border-accent"
            defaultValue={event.vodUrl ?? ""}
            name="vodUrl"
            placeholder="https://www.youtube.com/watch?v=..."
            type="url"
          />
          {vodUrlError ? <span className="text-xs text-danger" id={vodUrlErrorId}>{vodUrlError}</span> : null}
        </label>

        <label className="grid gap-2 text-sm font-semibold text-foreground">
          Slug du récap (optionnel)
          <input
            aria-describedby={slugError ? slugErrorId : undefined}
            aria-invalid={slugError ? true : undefined}
            className="min-h-11 rounded border border-border bg-background px-3 outline-none focus:border-accent"
            defaultValue={event.recapPostSlug ?? ""}
            name="recapPostSlug"
            placeholder="spring-sync-2027-recap"
            type="text"
          />
          {slugError ? <span className="text-xs text-danger" id={slugErrorId}>{slugError}</span> : null}
        </label>

        <div className="flex justify-end gap-3">
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-border px-3 text-sm font-semibold text-foreground hover:border-accent"
            onClick={onClose}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-3 text-sm font-semibold text-white hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
            disabled={submitting}
            type="submit"
          >
            {submitting ? "Enregistrement..." : "Enregistrer"}
          </button>
        </div>
      </form>
    </div>
  );
}

function extractDetails(payload: unknown): Record<string, string> {
  const details =
    payload && typeof payload === "object" && "error" in payload && typeof (payload as { error: unknown }).error === "object"
      ? ((payload as { error: { details?: unknown } }).error.details ?? {})
      : {};

  if (!details || typeof details !== "object") return {};

  const result: Record<string, string> = {};
  for (const [key, value] of Object.entries(details as Record<string, unknown>)) {
    if (Array.isArray(value) && typeof value[0] === "string") {
      result[key] = value[0];
    }
  }
  return result;
}

function statusLabel(status: AdminEvent["status"]) {
  return { draft: "Brouillon", published: "Publié", "in-progress": "En cours", completed: "Terminé" }[status];
}

function transitionActions(status: AdminEvent["status"]): StatusAction[] {
  const actions: Record<AdminEvent["status"], StatusAction[]> = {
    draft: [{ label: "Publier", status: "published" }],
    published: [{ label: "Dépublier", status: "draft" }, { label: "Démarrer", status: "in-progress" }],
    "in-progress": [{ label: "Revenir publié", status: "published" }, { label: "Terminer", status: "completed" }],
    completed: [{ label: "Rouvrir", status: "published" }],
  };
  return actions[status];
}

function transitionIcon(status: AdminEvent["status"]): LucideIcon {
  return {
    draft: RotateCcw,
    published: Eye,
    "in-progress": Play,
    completed: CheckCircle2,
  }[status];
}

function transitionConfirmation(from: AdminEvent["status"], to: AdminEvent["status"]) {
  if (to === "draft") return "Dépublier cet événement ? Il disparaîtra des pages publiques.";
  if (from === "draft" && to === "published") return "Publier cet événement ? Il deviendra visible sur les pages publiques.";
  return "Confirmer ce changement de statut ?";
}

function formatDateShort(value: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    day: "numeric",
    month: "short",
    year: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(value));
}

function isEventListPayload(payload: unknown): payload is { data: AdminEvent[] } {
  return Boolean(payload && typeof payload === "object" && "data" in payload && Array.isArray((payload as { data: unknown }).data));
}

function isEventPayload(payload: unknown): payload is { data: AdminEvent } {
  const data = payload && typeof payload === "object" && "data" in payload ? (payload as { data: unknown }).data : null;
  return Boolean(data && typeof data === "object" && "id" in data && "title" in data && "gameSelectionEnabled" in data && "hasRecap" in data);
}
