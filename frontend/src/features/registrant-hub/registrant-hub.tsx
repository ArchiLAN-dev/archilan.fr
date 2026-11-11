"use client";

import Link from "next/link";
import { Children, useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { AlertCircle, AlertTriangle, CalendarDays, CheckCircle, Gamepad2, Info, ListChecks, MapPin } from "lucide-react";

import { Markdown } from "@/components/markdown/markdown";

import type { EventStatus } from "@/features/events/event-types";
import { eventSchedule } from "@/features/events/event-schedule";
import { eventPhase, eventPhaseLabel, type EventPhase } from "./event-phase";
import { RegistrantChecklist } from "./registrant-checklist";
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

  return (
    <RegistrantHubView
      event={event}
      checklist={checklist?.items ?? null}
      now={now}
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
};

export function RegistrantHubView({ event, registration, now, practicalInfo = null, checklist = null }: ViewProps) {
  const phase = eventPhase(event, now);
  const registrationPath = `/evenements/${event.slug}/inscription/${registration.registrationId}`;

  return (
    <div className="grid gap-8">
      <RegistrantHubHeader event={event} now={now} phase={phase} registration={registration} />
      {checklist !== null ? (
        <RegistrantHubSection icon={<ListChecks aria-hidden="true" className="size-5" />} title="Ce qu'il me reste à faire">
          <RegistrantChecklist eventSlug={event.slug} items={checklist} registrationId={registration.registrationId} />
        </RegistrantHubSection>
      ) : null}
      <PracticalInfoSection practicalInfo={practicalInfo} />
      <div className="grid gap-6 lg:grid-cols-2">
        <RegistrantHubSection icon={<Gamepad2 aria-hidden="true" className="size-5" />} title="Mon inscription">
          <ul className="grid gap-2 text-sm">
            <li>
              <Link className="text-accent-text hover:text-accent-text-hover" href={`${registrationPath}/recap`}>
                Récapitulatif de mon inscription
              </Link>
            </li>
            {registration.confirmed ? (
              <li>
                <Link className="text-accent-text hover:text-accent-text-hover" href={`${registrationPath}/session`}>
                  Ma partie
                </Link>
              </li>
            ) : null}
          </ul>
        </RegistrantHubSection>
      </div>
    </div>
  );
}

const UPDATED_AT = new Intl.DateTimeFormat("fr-FR", { timeZone: "Europe/Paris", day: "numeric", month: "long" });

/** Story 53.4: the organisers' text, full width (it can be long), absent when they wrote nothing. */
function PracticalInfoSection({ practicalInfo }: { practicalInfo: PracticalInfo | null }) {
  const markdown = practicalInfo?.markdown ?? null;
  if (markdown === null || markdown.trim() === "") return null;

  return (
    <RegistrantHubSection icon={<Info aria-hidden="true" className="size-5" />} title="Infos pratiques">
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
    <header className="grid gap-5 border-b border-border pb-8">
      <Link
        className="inline-flex w-fit items-center text-sm text-muted-foreground transition-colors hover:text-foreground"
        href={`/evenements/${event.slug}`}
      >
        &larr; Page de l&apos;événement
      </Link>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="grid gap-1">
          <p className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Mon event</p>
          <h1 className="font-heading text-3xl font-bold leading-tight text-foreground md:text-4xl">{event.title}</h1>
        </div>
        <span className={`w-fit rounded border px-3 py-1 text-sm font-semibold ${PHASE_TONE[phase]}`}>
          {eventPhaseLabel(event, now)}
        </span>
      </div>
      <ul className="grid gap-2 text-sm text-muted-foreground">
        {event.startsAt !== undefined && event.endsAt !== undefined ? (
          <li className="flex items-start gap-2">
            <CalendarDays aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <span>{eventSchedule(event.startsAt, event.endsAt)}</span>
          </li>
        ) : null}
        {event.location !== "" ? (
          <li className="flex items-start gap-2">
            <MapPin aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <span>{event.location}</span>
          </li>
        ) : null}
      </ul>
      <RegistrationStatus eventSlug={event.slug} registration={registration} />
    </header>
  );
}

function RegistrationStatus({ eventSlug, registration }: { eventSlug: string; registration: RegistrantHubRegistration }) {
  if (registration.confirmed) {
    return (
      <p className="flex items-center gap-2 text-sm font-semibold text-success">
        <CheckCircle aria-hidden="true" className="size-4" />
        Inscription confirmée
      </p>
    );
  }

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
};

/** A block of the hub. A section with nothing to show is not rendered at all. */
export function RegistrantHubSection({ title, icon, children }: SectionProps) {
  if (Children.toArray(children).length === 0) return null;

  return (
    <section className="grid content-start gap-4 rounded-lg border border-border bg-surface p-5">
      <h2 className="flex items-center gap-2 font-heading text-lg font-semibold text-foreground">
        {icon ?? <ListChecks aria-hidden="true" className="size-5" />}
        {title}
      </h2>
      {children}
    </section>
  );
}

function RegistrantHubSkeleton() {
  return (
    <div aria-hidden="true" className="grid gap-8">
      <div className="grid gap-3 border-b border-border pb-8">
        <div className="h-4 w-40 animate-pulse rounded bg-surface-2" />
        <div className="h-9 w-72 animate-pulse rounded bg-surface-2" />
        <div className="h-4 w-56 animate-pulse rounded bg-surface-2" />
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        <div className="h-32 animate-pulse rounded-lg bg-surface-2" />
        <div className="h-32 animate-pulse rounded-lg bg-surface-2" />
      </div>
    </div>
  );
}
