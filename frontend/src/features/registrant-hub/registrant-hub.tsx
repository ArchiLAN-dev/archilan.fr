"use client";

import Link from "next/link";
import { Children, Fragment, useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { AlertCircle, AlertTriangle, BookOpen, CalendarDays, CheckCircle, Gamepad2, History, Info, ListChecks, MapPin } from "lucide-react";

import { Markdown } from "@/components/markdown/markdown";

import { ARCHIPELAGO_GUIDE_CARD } from "@/features/content/help-guide-views";
import { fetchHelpGuides, helpGuidePath, type HelpGuideCard } from "@/features/content/help-guides-api";
import type { EventStatus } from "@/features/events/event-types";
import { eventSchedule } from "@/features/events/event-schedule";
import { eventPhase, eventPhaseLabel, type EventPhase } from "./event-phase";
import { hubMainSections, type HubMainKey } from "./hub-sections";
import { HELP_GUIDES_QUERY_KEY, HubGame, HubParticipation, HubRelive, HubSideTools, HubSurveys } from "./hub-tools";
import { checklistReady, RegistrantChecklist } from "./registrant-checklist";
import {
  fetchPracticalInfo,
  fetchRegistrantChecklist,
  fetchRegistrantHubAccess,
  hubRedirect,
  type ChecklistItem,
  type PracticalInfo,
} from "./registrant-hub-api";
import { useMinuteClock } from "./use-minute-clock";

/** Story 53.1: the plain, serializable slice of the public event the hub needs. */
export type RegistrantHubEvent = {
  id: string;
  slug: string;
  title: string;
  status: EventStatus;
  startsAt?: string;
  endsAt?: string;
  location: string;
  /** Story 53.6: the replay of the event, once it is over. */
  vodUrl?: string | null;
  /** Story 53.6: the event page shows a photo gallery. */
  hasPhotos?: boolean;
};

export type RegistrantHubRegistration = {
  registrationId: string;
  confirmed: boolean;
};

type Props = {
  event: RegistrantHubEvent;
};

export function RegistrantHub({ event }: Props) {
  const router = useRouter();
  const now = useMinuteClock();
  const { data } = useQuery({
    queryKey: ["registrant-hub-access", event.id],
    queryFn: () => fetchRegistrantHubAccess(event.id),
    staleTime: 0,
    retry: false,
  });

  const registered = data?.kind === "registered";
  // Story 53.4: read once the visitor is known to be a registrant (anyone else gets a 403).
  const { data: practicalInfo } = useQuery({
    queryKey: ["registrant-hub-practical-info", event.id],
    queryFn: () => fetchPracticalInfo(event.id),
    enabled: registered,
    staleTime: 5 * 60_000,
    retry: false,
  });

  // Story 53.2: refetched on every visit, so a step done elsewhere is ticked on the way back.
  const registrationId = data?.kind === "registered" ? data.registrationId : null;
  const { data: checklist } = useQuery({
    queryKey: ["registrant-hub-checklist", registrationId],
    queryFn: () => (registrationId === null ? null : fetchRegistrantChecklist(registrationId)),
    enabled: registrationId !== null,
    staleTime: 0,
    retry: false,
  });

  // Story 53.5: the published guides, shared with the tool cards' "C'est quoi ?" links.
  const { data: guides } = useQuery({
    queryKey: HELP_GUIDES_QUERY_KEY,
    queryFn: fetchHelpGuides,
    enabled: registered,
    staleTime: 5 * 60_000,
    retry: false,
  });

  const redirect = data === undefined ? null : hubRedirect(data, event.slug);
  useEffect(() => {
    if (redirect !== null) router.replace(redirect);
  }, [redirect, router]);

  if (data?.kind === "error") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">Impossible de charger ton espace. Réessaie dans un instant.</p>
      </div>
    );
  }

  if (data?.kind !== "registered" || now === null) {
    return <RegistrantHubSkeleton />;
  }

  // Story 53.6: recomputed every minute with the clock, so the space turns to "during" without a reload.
  const phase = eventPhase(event, now);

  return (
    <RegistrantHubView
      event={event}
      checklist={checklist?.items ?? null}
      guides={guides ?? null}
      now={now}
      relive={
        <HubRelive
          confirmed={data.confirmed}
          eventSlug={event.slug}
          hasPhotos={event.hasPhotos ?? false}
          registrationId={data.registrationId}
          vodUrl={event.vodUrl ?? null}
        />
      }
      game={<HubGame confirmed={data.confirmed} eventId={event.id} eventSlug={event.slug} phase={phase} registrationId={data.registrationId} />}
      participation={<HubParticipation confirmed={data.confirmed} phase={phase} registrationId={data.registrationId} />}
      surveys={<HubSurveys eventId={event.id} eventSlug={event.slug} />}
      tools={<HubSideTools eventId={event.id} phase={phase} />}
      practicalInfo={practicalInfo ?? null}
      registration={{ registrationId: data.registrationId, confirmed: data.confirmed }}
    />
  );
}

type ViewProps = {
  event: RegistrantHubEvent;
  registration: RegistrantHubRegistration;
  now: Date;
  /** Story 53.4: null while loading, on a failure, or when the organisers wrote nothing. */
  practicalInfo?: PracticalInfo | null;
  /** Story 53.2: null while loading or on a failure (the section then stays out). */
  checklist?: ChecklistItem[] | null;
  /** Story 53.10: the member's game (main column), handed in by the gate (it reads its own data). */
  game?: ReactNode;
  /** Story 53.10: presence and stream (main column), handed in by the gate. */
  participation?: ReactNode;
  /** Story 53.10: the organisers' open surveys, for the "À faire maintenant" banner. */
  surveys?: ReactNode;
  /** Story 53.3: the small tools of the event (side column), handed in by the gate. */
  tools?: ReactNode;
  /** Story 53.5: the published guides; null while loading or on a failure (the Archipelago one still shows). */
  guides?: HelpGuideCard[] | null;
  /** Story 53.6: "Revivre l'event", handed in by the gate (it reads the member's game). */
  relive?: ReactNode;
};

/**
 * Story 53.10: the registrant space as a dashboard. A compact header with the moment of the event in large; a
 * "À faire maintenant" banner only when something waits (steps left, an open survey); then two columns on a wide
 * screen: the main one (the game, the practical info, or "Revivre l'event" afterwards) and the small tools.
 */
export function RegistrantHubView({
  event,
  registration,
  now,
  practicalInfo = null,
  checklist = null,
  game,
  participation,
  surveys,
  tools,
  guides = null,
  relive,
}: ViewProps) {
  const phase = eventPhase(event, now);
  const registrationPath = `/evenements/${event.slug}/inscription/${registration.registrationId}`;
  const context = { eventSlug: event.slug, registrationId: registration.registrationId };
  // Story 53.6: no checklist once the event is over.
  const shownChecklist = phase === "after" ? null : checklist;
  const ready = shownChecklist === null || checklistReady(shownChecklist, context);

  const main: Record<HubMainKey, ReactNode> = {
    // Story 53.6: nothing to tell the organisers once the event is over.
    game: phase === "after" ? null : game,
    participation: phase === "after" ? null : participation,
    practicalInfo: <PracticalInfoSection practicalInfo={practicalInfo} />,
    relive: (
      <RegistrantHubSection icon={<History aria-hidden="true" className="size-4" />} title="Revivre l'event">
        {relive}
      </RegistrantHubSection>
    ),
  };

  return (
    <div className="grid gap-6">
      <RegistrantHubHeader event={event} now={now} phase={phase} registration={registration} />

      {/* Story 53.10: what waits for the member, and nothing at all when nothing does. */}
      <div aria-label="À faire maintenant" className="flex flex-wrap gap-3 empty:hidden" role="region">
        {shownChecklist !== null && !ready ? (
          <section className="grid min-w-0 flex-[1_1_22rem] content-start gap-3 rounded-lg border border-warning/50 bg-warning/5 p-4">
            <h2 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground">
              <ListChecks aria-hidden="true" className="size-4 text-warning" />
              Ce qu&apos;il me reste à faire
            </h2>
            {/* Story 53.6: during the event the list folds behind its counter. */}
            <RegistrantChecklist {...context} folded={phase === "during"} items={shownChecklist} />
          </section>
        ) : null}
        {surveys !== undefined ? (
          <div className="min-w-0 flex-[1_1_22rem] scroll-mt-24 empty:hidden [&>*]:max-w-none!" id="sondages">
            {surveys}
          </div>
        ) : null}
      </div>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] xl:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
        <div className="grid min-w-0 content-start gap-4">
          {hubMainSections(phase).map((key) => (
            <Fragment key={key}>{main[key]}</Fragment>
          ))}
        </div>

        <aside aria-label="Mes outils" className="grid min-w-0 scroll-mt-24 content-start gap-4" id="outils">
          {tools}
          <RegistrantHubSection icon={<Gamepad2 aria-hidden="true" className="size-4" />} title="Mon inscription">
            <Link className="w-fit text-sm text-accent-text hover:text-accent-text-hover" href={`${registrationPath}/recap`}>
              Récapitulatif de mon inscription
            </Link>
            {/* Story 53.10: a done list stays at hand, out of the way. */}
            {shownChecklist !== null && ready ? <RegistrantChecklist {...context} items={shownChecklist} /> : null}
          </RegistrantHubSection>
          <GuidesSection guides={guides} />
        </aside>
      </div>
    </div>
  );
}

/** Story 53.5: the help guides, the Archipelago one first, for the "c'est quoi ce truc ?" moments. */
function GuidesSection({ guides }: { guides: HelpGuideCard[] | null }) {
  return (
    <RegistrantHubSection icon={<BookOpen aria-hidden="true" className="size-4" />} title="Les guides">
      {/* Story 53.10: titles only in the side column; the summary is on the guide (and in the tooltip). */}
      <ul className="grid gap-2 text-sm">
        {[ARCHIPELAGO_GUIDE_CARD, ...(guides ?? [])].map((guide) => (
          <li key={guide.slug}>
            <Link className="text-accent-text hover:text-accent-text-hover" href={helpGuidePath(guide.slug)} title={guide.summary}>
              {guide.title}
            </Link>
          </li>
        ))}
      </ul>
      <Link className="w-fit text-sm text-accent-text hover:text-accent-text-hover" href="/aide">
        Tous les guides
      </Link>
    </RegistrantHubSection>
  );
}

const UPDATED_AT = new Intl.DateTimeFormat("fr-FR", { timeZone: "Europe/Paris", day: "numeric", month: "long" });

/** Story 53.4: the organisers' text, full width (it can be long), absent when they wrote nothing. */
function PracticalInfoSection({ practicalInfo }: { practicalInfo: PracticalInfo | null }) {
  const markdown = practicalInfo?.markdown ?? null;
  if (markdown === null || markdown.trim() === "") return null;

  return (
    <RegistrantHubSection icon={<Info aria-hidden="true" className="size-4" />} title="Infos pratiques">
      <Markdown className="text-sm">{markdown}</Markdown>
      {practicalInfo?.updatedAt ? (
        <p className="text-xs text-muted-foreground">Mis à jour le {UPDATED_AT.format(new Date(practicalInfo.updatedAt))}</p>
      ) : null}
    </RegistrantHubSection>
  );
}

type HeaderProps = {
  event: RegistrantHubEvent;
  registration: RegistrantHubRegistration;
  phase: EventPhase;
  now: Date;
};

const PHASE_TONE: Record<EventPhase, string> = {
  before: "border-accent/50 text-accent-text",
  during: "border-special/60 text-special",
  after: "border-muted-foreground/40 text-muted-foreground",
};

function RegistrantHubHeader({ event, registration, phase, now }: HeaderProps) {
  return (
    <header className="grid gap-4 border-b border-border pb-6">
      <Link
        className="inline-flex w-fit items-center text-sm text-muted-foreground transition-colors hover:text-foreground"
        href={`/evenements/${event.slug}`}
      >
        &larr; Page de l&apos;événement
      </Link>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="grid min-w-0 gap-2">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Mon event</p>
          <h1 className="font-heading text-3xl font-bold leading-tight text-foreground md:text-4xl">{event.title}</h1>
          <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
            {event.startsAt !== undefined && event.endsAt !== undefined ? (
              <li className="flex items-start gap-1.5">
                <CalendarDays aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                <span>{eventSchedule(event.startsAt, event.endsAt)}</span>
              </li>
            ) : null}
            {event.location !== "" ? (
              <li className="flex items-start gap-1.5">
                <MapPin aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                <span>{event.location}</span>
              </li>
            ) : null}
            {registration.confirmed ? (
              <li className="flex items-center gap-1.5 font-semibold text-success">
                <CheckCircle aria-hidden="true" className="size-4" />
                Inscription confirmée
              </li>
            ) : null}
          </ul>
        </div>
        {/* Story 53.10: the moment of the event, in large - the first thing to read. */}
        <p
          className={`w-fit shrink-0 rounded-lg border-2 px-5 py-2 text-center font-heading text-3xl font-bold leading-tight md:text-4xl ${PHASE_TONE[phase]}`}
        >
          {eventPhaseLabel(event, now)}
        </p>
      </div>
      {registration.confirmed ? null : <RegistrationStatus eventSlug={event.slug} registration={registration} />}
    </header>
  );
}

function RegistrationStatus({ eventSlug, registration }: { eventSlug: string; registration: RegistrantHubRegistration }) {
  return (
    <div className="flex flex-col gap-2 rounded-lg border border-warning/50 p-4 sm:flex-row sm:items-center sm:justify-between">
      <p className="flex items-center gap-2 text-sm font-semibold text-warning">
        <AlertTriangle aria-hidden="true" className="size-4" />
        Inscription à confirmer
      </p>
      <Link
        className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
        href={`/evenements/${eventSlug}/inscription/${registration.registrationId}/recap`}
      >
        Confirmer mon inscription
      </Link>
    </div>
  );
}

type SectionProps = {
  title: string;
  icon?: ReactNode;
  children?: ReactNode;
  /** Anchor the checklist links to. */
  id?: string;
};

/** A block of the hub. A section with nothing to show is not rendered at all. */
export function RegistrantHubSection({ title, icon, children, id }: SectionProps) {
  if (Children.toArray(children).length === 0) return null;

  return (
    // Story 53.10: the same frame as the tools' tiles (border, padding, title size).
    <section className="grid min-w-0 scroll-mt-24 content-start gap-3 rounded-lg border border-border bg-surface p-4" id={id}>
      <h2 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground">
        {icon ?? <ListChecks aria-hidden="true" className="size-4" />}
        {title}
      </h2>
      {children}
    </section>
  );
}

function RegistrantHubSkeleton() {
  return (
    <div aria-hidden="true" className="grid gap-6">
      <div className="grid gap-3 border-b border-border pb-8">
        <div className="h-4 w-40 animate-pulse rounded bg-surface-2" />
        <div className="h-9 w-72 animate-pulse rounded bg-surface-2" />
        <div className="h-4 w-56 animate-pulse rounded bg-surface-2" />
      </div>
      {/* Story 53.10: the dashboard grid, main column and tools. */}
      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] xl:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
        <div className="grid gap-4">
          <div className="h-40 animate-pulse rounded-lg bg-surface-2" />
          <div className="h-32 animate-pulse rounded-lg bg-surface-2" />
        </div>
        <div className="grid gap-4">
          <div className="h-24 animate-pulse rounded-lg bg-surface-2" />
          <div className="h-24 animate-pulse rounded-lg bg-surface-2" />
        </div>
      </div>
    </div>
  );
}
