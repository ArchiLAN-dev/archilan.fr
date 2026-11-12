"use client";

import {
  CalendarClock,
  ClipboardList,
  Gamepad2,
  Handshake,
  House,
  KeyRound,
  MonitorUp,
  Pencil,
  QrCode,
  Server,
  ShieldAlert,
  Shovel,
  Users,
  Video,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import Link from "next/link";
import { notFound } from "next/navigation";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchAdminEvents, fetchAdminLocalStreams, LOCAL_STREAMS_REFRESH_MS, localStreamsRefetchInterval, mediaServerHubState, type AdminEvent, type AdminLocalStreams } from "../admin-events-api";
import {
  ActionIcon,
  EventActionDialogs,
  EventActionMessage,
  useAdminEventActions,
} from "./event-actions";
import { eventHubHref, transitionActions, type EventStatus } from "./event-status";

/**
 * Story 3.17: everything an event offers, in the order of its life. The hub reads the event from the admin list the
 * events page already loads (no route of its own) and shares its commands and dialogs with that list.
 */
export function AdminEventHub({ eventId }: { eventId: string }) {
  const actions = useAdminEventActions();
  const { data } = useQuery({
    queryKey: ["admin-events"],
    queryFn: fetchAdminEvents,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  // Story 47.9: the régie agent and the LAN streams, followed on the day.
  const { data: localStreams } = useQuery({
    queryKey: ["admin-local-streams", eventId],
    queryFn: () => fetchAdminLocalStreams(eventId),
    staleTime: LOCAL_STREAMS_REFRESH_MS,
    refetchInterval: (query) => localStreamsRefetchInterval(query.state.data),
    retry: false,
  });

  if (data === undefined) {
    return <p className="px-4 py-10 text-sm text-muted-foreground">Chargement…</p>;
  }
  if (data.kind !== "ready") {
    return (
      <div className="m-4 grid justify-items-center gap-3 rounded-lg border border-border bg-surface p-8 text-center">
        <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
        <p className="max-w-md text-sm leading-6 text-muted-foreground">{data.message}</p>
      </div>
    );
  }
  const event = data.events.find((candidate) => candidate.id === eventId);
  if (event === undefined) {
    notFound();
  }

  return (
    <div className="grid gap-6">
      <EventActionMessage message={actions.message} />
      <AdminEventHubView
        event={event}
        localStreams={localStreams ?? null}
        onConfigurePrivateAccess={actions.openPrivateAccess}
        onConfigureRecap={actions.openRecap}
        onFeature={actions.toggleFeatured}
        onTransition={actions.requestTransition}
      />
      <EventActionDialogs actions={actions} />
    </div>
  );
}

type HubEntry =
  | { kind: "link"; label: string; href: string; icon: LucideIcon; state?: string }
  | { kind: "button"; label: string; onClick: () => void; icon: LucideIcon; state?: string }
  | { kind: "note"; label: string; icon: LucideIcon; state: string };

export type HubSection = { title: string; entries: HubEntry[] };

type ViewProps = {
  event: AdminEvent;
  /** Story 47.9: null while unread or unreadable, then the line shows no state. */
  localStreams?: AdminLocalStreams | null;
  onConfigurePrivateAccess: (event: AdminEvent) => void;
  onConfigureRecap: (event: AdminEvent) => void;
  onFeature: (event: AdminEvent) => void;
  onTransition: (event: AdminEvent, status: EventStatus) => void;
};

export function hubSections({ event, localStreams = null, onConfigurePrivateAccess, onConfigureRecap, onFeature }: Omit<ViewProps, "onTransition">): HubSection[] {
  const base = eventHubHref(event.id);

  const privateAccess: HubEntry = event.isPublic
    ? { kind: "note", label: "Accès privé", icon: KeyRound, state: "Événement public" }
    : {
        kind: "button",
        label: "Accès privé",
        icon: KeyRound,
        onClick: () => onConfigurePrivateAccess(event),
        state: event.hasPrivateAccessPassword ? "Mot de passe défini" : "Sans mot de passe",
      };
  const run: HubEntry = event.gameSelectionEnabled
    ? { kind: "link", label: "Run et sessions", href: `${base}/session`, icon: Server }
    : { kind: "note", label: "Run et sessions", icon: Server, state: "Activer d'abord la sélection de jeux" };
  const recap: HubEntry =
    event.status === "completed"
      ? { kind: "button", label: "Récap / VOD", icon: Video, onClick: () => onConfigureRecap(event), state: event.hasRecap ? "Récap attaché" : "À attacher" }
      : { kind: "note", label: "Récap / VOD", icon: Video, state: "Une fois l'événement terminé" };
  const featured: HubEntry =
    event.status === "draft"
      ? { kind: "note", label: "Accueil du site", icon: House, state: "Publier d'abord l'événement" }
      : {
          kind: "button",
          label: event.featuredOnHome ? "Retirer de l'accueil" : "Mettre en avant",
          icon: House,
          onClick: () => onFeature(event),
          state: event.featuredOnHome ? "Mis en avant" : "Choix automatique",
        };

  return [
    {
      title: "Préparation",
      entries: [
        { kind: "link", label: "Informations", href: `${base}/modifier`, icon: Pencil, state: event.venue || undefined },
        { kind: "link", label: "Jeux", href: `${base}/jeux`, icon: Gamepad2, state: event.gameSelectionEnabled ? "Sélection activée" : "Sélection non configurée" },
        { kind: "link", label: "Partenaires", href: `${base}/partenaires`, icon: Handshake },
        privateAccess,
      ],
    },
    {
      title: "Joueurs",
      entries: [
        {
          kind: "link",
          label: "Inscriptions",
          href: `${base}/inscriptions`,
          icon: Users,
          state: `${event.confirmedRegistrations}/${event.capacity} inscrits${event.isAtCapacity ? " - complet" : ""}`,
        },
        { kind: "link", label: "Présences", href: `${base}/presences`, icon: CalendarClock },
        { kind: "link", label: "Sondages", href: `${base}/sondages`, icon: ClipboardList },
        { kind: "link", label: "Pelles", href: `${base}/pelles`, icon: Shovel },
      ],
    },
    // Story 53.7: the QR code of the registrant space, printed for the LAN's tables.
    {
      title: "Jour J",
      entries: [
        run,
        // Story 47.9: the LAN media server and its régie agent, set on the event's page.
        { kind: "link", label: "Serveur média", href: `${base}/modifier`, icon: MonitorUp, state: mediaServerHubState(localStreams) },
        { kind: "link", label: "QR code de l'espace", href: `${base}/qr-espace`, icon: QrCode },
      ],
    },
    { title: "Après", entries: [recap, featured] },
  ];
}

const ENTRY =
  "flex min-h-14 items-center gap-3 rounded-md border border-border px-3 py-2 text-left transition-colors hover:border-accent";

export function AdminEventHubView(props: ViewProps) {
  const { event, onTransition } = props;

  return (
    <>
      {/* Story 3.18: the event bar above carries the name, state and dates; the overview keeps what to do next. */}
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="font-heading text-lg font-semibold text-foreground">Vue d&apos;ensemble</h1>
        <div className="flex flex-wrap gap-2">
          {transitionActions(event.status).map((action) => (
              <button
                className="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text"
                key={action.status}
                onClick={() => onTransition(event, action.status)}
                type="button"
              >
                <ActionIcon to={action.status} />
                {action.label}
              </button>
          ))}
        </div>
      </header>

      <div className="grid gap-4 md:grid-cols-2">
        {hubSections(props).map((section) => (
          <section className="grid content-start gap-2 rounded-lg border border-border bg-surface p-4" key={section.title}>
            <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{section.title}</h2>
            {section.entries.map((entry) => (
              <HubEntryView entry={entry} key={entry.label} />
            ))}
          </section>
        ))}
      </div>
    </>
  );
}

function HubEntryView({ entry }: { entry: HubEntry }) {
  const Icon = entry.icon;
  const content = (
    <>
      <Icon aria-hidden="true" className="size-4 shrink-0 text-accent-text" />
      <span className="grid min-w-0">
        <span className="text-sm font-semibold text-foreground">{entry.label}</span>
        {entry.state ? <span className="truncate text-xs text-muted-foreground">{entry.state}</span> : null}
      </span>
    </>
  );

  if (entry.kind === "link") {
    return (
      <Link className={ENTRY} href={entry.href}>
        {content}
      </Link>
    );
  }
  if (entry.kind === "button") {
    return (
      <button className={ENTRY} onClick={entry.onClick} type="button">
        {content}
      </button>
    );
  }
  return <div className="flex min-h-14 items-center gap-3 rounded-md border border-dashed border-border px-3 py-2 opacity-70">{content}</div>;
}
