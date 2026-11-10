"use client";

import { CheckCircle2, Eye, House, Play, RotateCcw, Video } from "lucide-react";
import type { FormEvent } from "react";
import { useId, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";

import {
  featureAdminEventOnHome,
  saveAdminEventRecap,
  updateAdminEventPrivateAccess,
  updateAdminEventStatus,
  type AdminEvent,
  type AdminEventsResult,
} from "../admin-events-api";
import { STATUS_PILL, statusLabel, transitionConfirmation, type EventStatus } from "./event-status";

/**
 * Story 3.17: the commands an event offers (status, home page, private access, recap) and their dialogs, shared by
 * the events list and the event hub - both read and patch the same cached list.
 */

type PendingTransition = { event: AdminEvent; status: EventStatus };

export type AdminEventActions = {
  message: string | null;
  requestTransition: (event: AdminEvent, status: EventStatus) => void;
  toggleFeatured: (event: AdminEvent) => Promise<void>;
  openPrivateAccess: (event: AdminEvent) => void;
  openRecap: (event: AdminEvent) => void;
  dialogs: {
    pendingTransition: PendingTransition | null;
    privateAccessEvent: AdminEvent | null;
    recapEvent: AdminEvent | null;
    cancelTransition: () => void;
    executeTransition: () => Promise<void>;
    closePrivateAccess: () => void;
    configurePrivateAccess: (event: AdminEvent, password: string) => Promise<void>;
    closeRecap: () => void;
    recapSaved: (event: AdminEvent, vodUrl: string | null, recapPostSlug: string | null) => void;
  };
};

export function useAdminEventActions(): AdminEventActions {
  const queryClient = useQueryClient();
  const [message, setMessage] = useState<string | null>(null);
  const [pendingTransition, setPendingTransition] = useState<PendingTransition | null>(null);
  const [privateAccessEvent, setPrivateAccessEvent] = useState<AdminEvent | null>(null);
  const [recapEvent, setRecapEvent] = useState<AdminEvent | null>(null);

  // Each mutation patches the cached list with the API's answer instead of refetching (no flash).
  function patchCachedEvent(mapper: (item: AdminEvent) => AdminEvent) {
    queryClient.setQueryData<AdminEventsResult>(["admin-events"], (prev) =>
      prev?.kind === "ready" ? { kind: "ready", events: prev.events.map(mapper) } : prev,
    );
  }

  async function executeTransition() {
    if (!pendingTransition) return;

    const { event, status } = pendingTransition;
    setPendingTransition(null);

    const updated = await updateAdminEventStatus(event.id, status);
    if (updated === null) {
      setMessage("Transition de statut impossible.");
      return;
    }

    patchCachedEvent((item) => (item.id === updated.id ? updated : item));
    setMessage(status === "draft" ? "Événement dépublié." : "Statut mis à jour.");
  }

  // Story 3.15: one event at most is featured, so featuring one clears the others in the cached list too.
  async function toggleFeatured(event: AdminEvent) {
    const featured = !event.featuredOnHome;
    const error = await featureAdminEventOnHome(event.id, featured);
    if (error !== null) {
      setMessage(error);
      return;
    }

    patchCachedEvent((item) =>
      item.id === event.id ? { ...item, featuredOnHome: featured } : featured ? { ...item, featuredOnHome: false } : item,
    );
    setMessage(featured ? `${event.title} est mis en avant sur l'accueil.` : `${event.title} n'est plus mis en avant sur l'accueil.`);
  }

  async function configurePrivateAccess(event: AdminEvent, password: string) {
    const updated = await updateAdminEventPrivateAccess(event.id, password);
    if (updated === null) {
      setMessage("Configuration d'accès privé impossible.");
      return;
    }

    patchCachedEvent((item) => (item.id === updated.id ? updated : item));
    setMessage("Accès privé configuré.");
    setPrivateAccessEvent(null);
  }

  function recapSaved(event: AdminEvent, vodUrl: string | null, recapPostSlug: string | null) {
    patchCachedEvent((item) =>
      item.id === event.id ? { ...item, vodUrl, recapPostSlug, hasRecap: vodUrl !== null || recapPostSlug !== null } : item,
    );
    setRecapEvent(null);
    setMessage("Récap mis à jour.");
  }

  return {
    message,
    requestTransition: (event, status) => setPendingTransition({ event, status }),
    toggleFeatured,
    openPrivateAccess: setPrivateAccessEvent,
    openRecap: setRecapEvent,
    dialogs: {
      pendingTransition,
      privateAccessEvent,
      recapEvent,
      cancelTransition: () => setPendingTransition(null),
      executeTransition,
      closePrivateAccess: () => setPrivateAccessEvent(null),
      configurePrivateAccess,
      closeRecap: () => setRecapEvent(null),
      recapSaved,
    },
  };
}

export function EventActionMessage({ message }: { message: string | null }) {
  return message ? (
    <p className="border border-success/50 bg-surface p-3 text-sm text-success" role="status">
      {message}
    </p>
  ) : null;
}

export function EventActionDialogs({ actions }: { actions: AdminEventActions }) {
  const { dialogs } = actions;
  const recapEvent = dialogs.recapEvent;

  return (
    <>
      {dialogs.pendingTransition ? (
        <StatusTransitionDialog
          onCancel={dialogs.cancelTransition}
          onConfirm={dialogs.executeTransition}
          pendingTransition={dialogs.pendingTransition}
        />
      ) : null}
      {dialogs.privateAccessEvent ? (
        <PrivateAccessDialog
          event={dialogs.privateAccessEvent}
          onCancel={dialogs.closePrivateAccess}
          onSubmit={dialogs.configurePrivateAccess}
        />
      ) : null}
      {recapEvent ? (
        <RecapDialog
          event={recapEvent}
          onClose={dialogs.closeRecap}
          onSaved={(vodUrl, recapPostSlug) => dialogs.recapSaved(recapEvent, vodUrl, recapPostSlug)}
        />
      ) : null}
    </>
  );
}

/** Story 3.15: the event the home page shows instead of its automatic pick. */
export function FeaturedPill({ event }: { event: AdminEvent }) {
  return event.featuredOnHome ? (
    <span className="ml-2 inline-flex items-center gap-1 rounded-full border border-accent-warm/50 px-2 py-0.5 align-middle text-xs font-medium text-accent-warm">
      <House aria-hidden="true" className="size-3" />
      Accueil
    </span>
  ) : null;
}

/** The icon of a transition, by the status it leads to; `recap` for attaching the recap. */
export function ActionIcon({ to }: { to: EventStatus | "recap" }) {
  const className = "size-3.5";
  switch (to) {
    case "draft":
      return <RotateCcw aria-hidden="true" className={className} />;
    case "published":
      return <Eye aria-hidden="true" className={className} />;
    case "in-progress":
      return <Play aria-hidden="true" className={className} />;
    case "completed":
      return <CheckCircle2 aria-hidden="true" className={className} />;
    case "recap":
      return <Video aria-hidden="true" className={className} />;
  }
}

export function StatusPill({ status }: { status: EventStatus }) {
  return (
    <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${STATUS_PILL[status]}`}>
      {statusLabel(status)}
    </span>
  );
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

    const result = await saveAdminEventRecap(event.id, vodUrl, recapPostSlug);
    setSubmitting(false);

    if (!result.ok) {
      if (result.reason === "network") {
        setGenericError("Impossible de contacter l'API.");
        return;
      }
      if (result.details.vodUrl) setVodUrlError(result.details.vodUrl);
      if (result.details.recapPostSlug) setSlugError(result.details.recapPostSlug);
      if (!result.details.vodUrl && !result.details.recapPostSlug) setGenericError("Impossible d'enregistrer le récap.");
      return;
    }

    onSaved(vodUrl, recapPostSlug);
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
