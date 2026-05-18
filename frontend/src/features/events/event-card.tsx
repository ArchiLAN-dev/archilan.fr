import Link from "next/link";
import Image from "next/image";
import { CalendarDays, ImageIcon, Lock, MapPin, Radio, Trophy, Users } from "lucide-react";
import type { EventStatus, PublicEvent } from "./event-types";
import { externalLinks } from "@/lib/external-links";
import { getCapacityBarColor, getCapacityPercent } from "./event-utils";

type StatusConfig = { label: string; cta?: string; tone: string };

const statusCopy: Record<EventStatus, StatusConfig> = {
  open: {
    label: "Inscriptions ouvertes",
    cta: "Choisir mes jeux",
    tone: "border-success/50 text-success",
  },
  upcoming: {
    label: "Bientôt disponible",
    cta: "Voir l'événement",
    tone: "border-accent/50 text-accent-text",
  },
  full: {
    label: "Complet",
    cta: "Liste d'attente",
    tone: "border-danger/60 text-danger",
  },
  completed: {
    label: "Terminé",
    cta: "Voir le récap",
    tone: "border-muted-foreground/40 text-muted-foreground",
  },
  "members-only": {
    label: "Réservé aux membres",
    tone: "border-special/60 text-special",
  },
};

function EventMeta({ icon: Icon, children }: { icon: typeof CalendarDays; children: React.ReactNode }) {
  return (
    <span className="inline-flex items-center gap-2 text-sm text-muted-foreground">
      <Icon aria-hidden="true" className="size-4 text-accent-text" />
      {children}
    </span>
  );
}

export function EventCard({ event }: { event: PublicEvent }) {
  const state = statusCopy[event.status];
  const capacityPercent = getCapacityPercent(event.capacity);
  const barColor = getCapacityBarColor(capacityPercent);

  return (
    <article className="card-glow grid overflow-hidden rounded-lg border border-border">
      <EventCover event={event} />

      <div className="grid p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h3 className="font-heading text-2xl font-semibold leading-tight text-foreground">{event.title}</h3>
        </div>
        <span className={`shrink-0 rounded border px-2 py-1 text-xs font-semibold ${state.tone}`}>
          {state.label}
        </span>
      </div>

      <div className="mt-5 grid gap-2">
        <EventMeta icon={CalendarDays}>
          {event.dateIso ? (
            <time dateTime={event.dateIso}>{event.date}</time>
          ) : (
            event.date
          )}
        </EventMeta>
        <EventMeta icon={MapPin}>{event.location}</EventMeta>
      </div>

      {event.capacity ? (
        <div className="mt-5">
          <div className="flex items-center justify-between gap-3 text-sm">
            <span className="inline-flex items-center gap-2 text-muted-foreground">
              <Users aria-hidden="true" className="size-4 text-accent-text" />
              Places
            </span>
            <strong className="text-foreground">
              {event.capacity.remaining} / {event.capacity.total}
            </strong>
          </div>
          <div className="mt-2 h-2 rounded bg-surface">
            <div className={`h-2 rounded ${barColor} transition-all`} style={{ width: `${capacityPercent}%` }} />
          </div>
        </div>
      ) : null}

      {event.stats ? (
        <dl className="mt-5 grid grid-cols-3 gap-3 border-t border-border pt-5 text-sm">
          <div>
            <dt className="text-muted-foreground">Joueurs</dt>
            <dd className="font-semibold text-foreground">{event.stats.players}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Jeux</dt>
            <dd className="font-semibold text-foreground">{event.stats.games}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Durée</dt>
            <dd className="font-semibold text-foreground">{event.stats.duration}</dd>
          </div>
        </dl>
      ) : null}

      {event.status === "completed" ? (
        <p className="mt-5 inline-flex items-center gap-2 text-sm text-muted-foreground">
          <Trophy aria-hidden="true" className="size-4 text-accent-warm" />
          {event.recapAvailable ? "Récap disponible" : "Récap en préparation"}
        </p>
      ) : null}

      {event.status === "members-only" ? (
        <p className="mt-5 inline-flex items-center gap-2 text-sm text-muted-foreground">
          <Lock aria-hidden="true" className="size-4 text-special" />
          Accès réservé aux membres ArchiLAN.
        </p>
      ) : null}

      {state.cta ? (
        <div className="mt-auto pt-6">
          <Link
            className="inline-flex w-full min-h-11 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href={`/evenements/${event.id}`}
          >
            {state.cta}
          </Link>
        </div>
      ) : null}
      </div>
    </article>
  );
}

function EventCover({ event }: { event: PublicEvent }) {
  return (
    <div className="relative aspect-[16/9] overflow-hidden border-b border-border bg-surface">
      {event.coverImageUrl ? (
        <Image
          alt=""
          aria-hidden="true"
          className="object-cover"
          fill
          sizes="(max-width: 768px) 100vw, (max-width: 1024px) 50vw, 33vw"
          src={event.coverImageUrl}
          unoptimized={event.coverImageUrl.startsWith("http://") || event.coverImageUrl.startsWith("https://")}
        />
      ) : (
        <div className="flex h-full items-center justify-center bg-[linear-gradient(135deg,color-mix(in_oklab,var(--color-surface)_88%,var(--color-accent)),var(--color-background))]">
          <ImageIcon aria-hidden="true" className="size-10 text-muted-foreground/45" />
        </div>
      )}
      <div className="absolute inset-0 bg-gradient-to-t from-background/65 via-transparent to-transparent" />
    </div>
  );
}

export function EventsEmptyState() {
  return (
    <div className="card-glow rounded-lg border border-border p-6 text-center">
      <Radio aria-hidden="true" className="mx-auto mb-4 size-8 text-accent-text" />
      <p className="font-heading text-xl font-semibold text-foreground">
        Aucun événement prévu pour le moment
      </p>
      <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-muted-foreground">
        Suis-nous sur Twitch pour être le premier informé.
      </p>
      <a
        className="mt-5 inline-flex min-h-11 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
        href={externalLinks.twitch}
        rel="noopener noreferrer"
        target="_blank"
      >
        Ouvrir Twitch
      </a>
    </div>
  );
}
