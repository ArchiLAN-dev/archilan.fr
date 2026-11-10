"use client";

import { CalendarPlus, Plus, ShieldAlert, Users } from "lucide-react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchAdminEvents, type AdminEvent } from "./admin-events-api";
import {
  ActionIcon,
  EventActionDialogs,
  EventActionMessage,
  FeaturedPill,
  StatusPill,
  useAdminEventActions,
} from "./event-hub/event-actions";
import { EventRowMenu } from "./event-hub/event-row-menu";
import { eventHubHref, formatDateShort, primaryAction, visibilityLabel, type EventStatus } from "./event-hub/event-status";

const PRIMARY_BTN =
  "inline-flex min-h-8 items-center justify-center gap-1.5 rounded-md border border-accent/60 px-2.5 text-xs font-semibold text-accent-text transition-colors hover:border-accent hover:bg-accent/10";

type DashboardState =
  | { kind: "loading" }
  | { kind: "ready"; events: AdminEvent[] }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

export function AdminEventDashboard() {
  const actions = useAdminEventActions();

  // fetchAdminEvents never throws (denied/error are encoded in the result kind), so the query never errors.
  const { data } = useQuery({
    queryKey: ["admin-events"],
    queryFn: fetchAdminEvents,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const state: DashboardState = data ?? { kind: "loading" };

  return (
    <section className="grid w-full gap-8 px-4 py-10">
      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
          <div>
            <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">Événements</h1>
            <p className="mt-3 max-w-2xl text-muted-foreground">
              Gère les événements ArchiLAN : ouvre un événement pour ses inscriptions, ses jeux, sa run et le reste.
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

      <EventActionMessage message={actions.message} />

      <EventList
        onConfigurePrivateAccess={actions.openPrivateAccess}
        onConfigureRecap={actions.openRecap}
        onFeature={actions.toggleFeatured}
        onTransition={actions.requestTransition}
        state={state}
      />

      <EventActionDialogs actions={actions} />
    </section>
  );
}

type EventListProps = {
  onConfigurePrivateAccess: (event: AdminEvent) => void;
  onConfigureRecap: (event: AdminEvent) => void;
  onFeature: (event: AdminEvent) => void;
  onTransition: (event: AdminEvent, status: EventStatus) => void;
  state: DashboardState;
};

export function EventList({ onConfigurePrivateAccess, onConfigureRecap, onFeature, onTransition, state }: EventListProps) {
  if (state.kind === "loading") {
    return (
      <div className="grid gap-px overflow-hidden rounded-lg border border-border bg-border" aria-busy="true">
        {Array.from({ length: 4 }, (_, i) => `skeleton-${i}`).map((key) => (
          <div className="flex animate-pulse items-center gap-4 bg-surface px-4 py-4" key={key}>
            <div className="h-3.5 w-40 rounded bg-surface-2" />
            <div className="h-3 w-16 rounded bg-surface-2" />
            <div className="ml-auto h-7 w-24 rounded bg-surface-2" />
          </div>
        ))}
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

  // Most recent first.
  const sortedEvents = [...state.events].sort((a, b) => b.startsAt.localeCompare(a.startsAt));

  const renderActions = (event: AdminEvent) => (
    <div className="flex items-center justify-end gap-1.5">
      <PrimaryActionButton event={event} onConfigureRecap={onConfigureRecap} onTransition={onTransition} />
      <EventRowMenu
        event={event}
        onConfigurePrivateAccess={onConfigurePrivateAccess}
        onFeature={onFeature}
        onTransition={onTransition}
      />
    </div>
  );

  return (
    <div className="rounded-lg border border-border bg-surface">
      {/* Desktop: one line per event, its next step and a menu for the rest. */}
      <table className="hidden w-full border-collapse text-left text-sm lg:table">
        <thead className="border-b border-border text-muted-foreground">
          <tr>
            <th className="px-4 py-3 font-medium">Titre</th>
            <th className="px-4 py-3 font-medium">Statut</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Début</th>
            <th className="whitespace-nowrap px-4 py-3 font-medium">Inscrits</th>
            <th className="px-4 py-3 font-medium">Visibilité</th>
            <th className="px-4 py-3 text-right font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          {sortedEvents.map((event) => (
            <tr className="border-b border-border last:border-b-0" key={event.id}>
              <td className="px-4 py-3 font-semibold text-foreground">
                <Link className="hover:text-accent-text hover:underline" href={eventHubHref(event.id)}>
                  {event.title}
                </Link>
                <FeaturedPill event={event} />
              </td>
              <td className="whitespace-nowrap px-4 py-3">
                <StatusPill status={event.status} />
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">
                <time dateTime={event.startsAt}>{formatDateShort(event.startsAt)}</time>
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">
                <Capacity event={event} />
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{visibilityLabel(event)}</td>
              <td className="px-4 py-3">{renderActions(event)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* Mobile: one card per event (no horizontal scroll). */}
      <ul className="divide-y divide-border lg:hidden">
        {sortedEvents.map((event) => (
          <li className="space-y-3 p-4" key={event.id}>
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="font-semibold text-foreground">
                  <Link className="hover:text-accent-text hover:underline" href={eventHubHref(event.id)}>
                    {event.title}
                  </Link>
                  <FeaturedPill event={event} />
                </p>
                <div className="mt-1">
                  <StatusPill status={event.status} />
                </div>
              </div>
              <time className="shrink-0 text-xs text-muted-foreground" dateTime={event.startsAt}>
                {formatDateShort(event.startsAt)}
              </time>
            </div>
            <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
              <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <Capacity event={event} />
                <span>{visibilityLabel(event)}</span>
              </span>
              {renderActions(event)}
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

function Capacity({ event }: { event: AdminEvent }) {
  return (
    <span className="inline-flex items-center gap-2">
      <span>
        {event.confirmedRegistrations}/{event.capacity}
      </span>
      {event.isAtCapacity ? (
        <span className="inline-flex items-center gap-1 rounded border border-danger/40 bg-danger/10 px-1.5 py-0.5 text-xs font-semibold text-danger">
          <Users aria-hidden="true" className="size-3" />
          Complet
        </span>
      ) : null}
    </span>
  );
}

function PrimaryActionButton({
  event,
  onConfigureRecap,
  onTransition,
}: {
  event: AdminEvent;
  onConfigureRecap: (event: AdminEvent) => void;
  onTransition: (event: AdminEvent, status: EventStatus) => void;
}) {
  const action = primaryAction(event);
  if (action === null) return null;

  return (
    <button
      className={PRIMARY_BTN}
      onClick={() => (action.kind === "recap" ? onConfigureRecap(event) : onTransition(event, action.status))}
      type="button"
    >
      <ActionIcon to={action.kind === "recap" ? "recap" : action.status} />
      {action.label}
    </button>
  );
}
