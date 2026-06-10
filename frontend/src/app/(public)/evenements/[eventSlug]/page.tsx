import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";
import { AlertTriangle, CalendarDays, ExternalLink, ImageIcon, MapPin, RefreshCw, Trophy, Users } from "lucide-react";
import { externalLinks } from "@/lib/external-links";
import type { EventAttendanceMode, EventStatus, PublicEvent } from "@/features/events/event-types";
import { getPublicEvent, getPublicEvents } from "@/features/events/public-events-api";
import { EventCheckout } from "@/features/events/event-checkout";
import { EventRegistrationCta } from "@/features/events/event-registration-cta";
import { LiveSeatCounter } from "@/features/events/live-seat-counter";

type EventDetailPageProps = {
  params: Promise<{ eventSlug: string }>;
};

type DetailStatus = {
  label: string;
  description: string;
  tone: string;
  cta?: {
    label: string;
    href: string;
    external?: boolean;
  };
};

import { env } from "@/lib/env";

const statusDetails: Record<EventStatus, DetailStatus> = {
  open: {
    label: "Inscriptions ouvertes",
    description: "Des places sont encore disponibles. Prépare tes jeux et rejoins la session.",
    tone: "border-success/50 text-success",
    cta: { label: "S'inscrire à cet événement", href: "inscription" },
  },
  upcoming: {
    label: "Bientôt disponible",
    description: "Les détails sont publiés, les inscriptions ouvriront plus tard.",
    tone: "border-accent/50 text-accent-text",
    cta: { label: "Suivre les annonces", href: externalLinks.archipelagoDiscord, external: true },
  },
  full: {
    label: "Complet",
    description: "La capacité annoncée est atteinte. Surveille les annonces pour les prochaines sessions.",
    tone: "border-danger/60 text-danger",
    cta: { label: "Suivre les annonces", href: externalLinks.archipelagoDiscord, external: true },
  },
  completed: {
    label: "Terminé",
    description: "Cet événement est terminé. Consulte le récap ou la VOD quand ils sont disponibles.",
    tone: "border-muted-foreground/40 text-muted-foreground",
  },
  "members-only": {
    label: "Réservé aux membres",
    description: "Cet événement est publié, mais l'accès est réservé aux membres ArchiLAN.",
    tone: "border-special/60 text-special",
  },
};

const schemaStatusMap: Record<EventStatus, string> = {
  open: "https://schema.org/EventScheduled",
  upcoming: "https://schema.org/EventScheduled",
  full: "https://schema.org/EventScheduled",
  completed: "https://schema.org/EventCompleted",
  "members-only": "https://schema.org/EventScheduled",
};

const schemaAttendanceModeMap: Record<EventAttendanceMode, string> = {
  offline: "https://schema.org/OfflineEventAttendanceMode",
  online: "https://schema.org/OnlineEventAttendanceMode",
  mixed: "https://schema.org/MixedEventAttendanceMode",
};

export async function generateStaticParams() {
  const { upcoming, past } = await getPublicEvents();
  return [...upcoming, ...past].map((event) => ({ eventSlug: event.id }));
}

export async function generateMetadata({ params }: EventDetailPageProps): Promise<Metadata> {
  const { eventSlug } = await params;
  const event = await getPublicEvent(eventSlug);

  if (!event) {
    return {
      title: "Événement introuvable",
      robots: { index: false, follow: false },
    };
  }

  const canonicalPath = `/evenements/${event.id}`;
  const description = event.description;

  return {
    title: event.title,
    description,
    metadataBase: new URL(env.appUrl),
    alternates: {
      canonical: canonicalPath,
    },
    openGraph: {
      title: `${event.title} | ArchiLAN`,
      description,
      url: canonicalPath,
      siteName: "ArchiLAN",
      type: "website",
      locale: "fr_FR",
      ...(event.coverImageUrl ? { images: [{ url: event.coverImageUrl, alt: event.title }] } : {}),
    },
    twitter: {
      card: "summary",
      title: `${event.title} | ArchiLAN`,
      description,
    },
  };
}

export default async function EventDetailPage({ params }: EventDetailPageProps) {
  const { eventSlug } = await params;
  const event = await getPublicEvent(eventSlug);

  if (!event) {
    notFound();
  }

  const status = statusDetails[event.status];
  // "S'inscrire" is moved to the aside below the seat counter.
  // Only keep header CTA for non-registration actions (checkout, announcements…).
  const primaryCta = (() => {
    if (event.checkoutEmbedUrl && event.status === "open") {
      return { label: "Acheter un billet", href: "#billetterie" };
    }
    if (!status.cta || status.cta.href === "inscription") return undefined;
    return { ...status.cta, href: status.cta.href };
  })();
  const canonicalUrl = new URL(`/evenements/${event.id}`, env.appUrl).toString();
  const structuredData = getEventStructuredData(event, canonicalUrl);

  return (
    <>
      <script
        dangerouslySetInnerHTML={{
          __html: JSON.stringify(structuredData)
            .replace(/</g, "\\u003c")
            .replace(/>/g, "\\u003e")
            .replace(/&/g, "\\u0026"),
        }}
        type="application/ld+json"
      />

      <article className="mx-auto w-full max-w-7xl grid gap-12">
        <header className="grid gap-6 border-b border-border pb-10">
          <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
              <h1 className="max-w-4xl font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
                {event.title}
              </h1>
            </div>
            <span className={`w-fit rounded border px-3 py-1 text-sm font-semibold ${status.tone}`}>
              {status.label}
            </span>
          </div>

          <p className="text-lg leading-8 text-muted-foreground">{event.description}</p>

          <div className="flex flex-col gap-3 sm:flex-row">
            {primaryCta ? <DetailCta cta={primaryCta} /> : null}
            {event.status === "completed" && event.vodUrl ? (
              <a
                className="inline-flex min-h-12 items-center justify-center gap-2 rounded border border-border bg-surface px-5 font-semibold text-foreground transition-colors hover:border-accent"
                href={event.vodUrl}
                rel="noopener noreferrer"
                target="_blank"
              >
                Voir la VOD
                <ExternalLink aria-hidden="true" className="size-4" />
              </a>
            ) : null}
          </div>
        </header>

        <EventHeroImage event={event} />

        <section className="grid gap-6 lg:grid-cols-[0.72fr_0.28fr]" id="details-pratiques">
          <div className="rounded-lg border border-border p-6">
            <h2 className="font-heading text-2xl font-semibold text-foreground">
              Détails pratiques
            </h2>
            <dl className="mt-6 grid gap-4 text-sm">
              <DetailMeta icon={CalendarDays} label="Date">
                {event.dateIso ? <time dateTime={event.dateIso}>{event.date}</time> : event.date}
              </DetailMeta>
              <DetailMeta icon={MapPin} label="Lieu">
                {event.location}
              </DetailMeta>
              <DetailMeta icon={Users} label="Disponibilité">
                {status.description}
              </DetailMeta>
            </dl>
          </div>

          <aside className="grid gap-4">
            {event.capacity ? (
              <LiveSeatCounter
                eventId={event.id}
                initialCapacity={event.capacity.total}
                initialConfirmedRegistrations={event.capacity.total - event.capacity.remaining}
              />
            ) : (
              <div className="rounded-lg border border-border p-6">
                <h2 className="font-heading text-2xl font-semibold text-foreground">
                  Disponibilité
                </h2>
                <p className="mt-5 text-sm leading-6 text-muted-foreground">
                  Les places seront précisées quand l&apos;organisation publiera la capacité.
                </p>
              </div>
            )}
            {event.status === "open" ? (
              <EventRegistrationCta eventId={event.id} eventSlug={eventSlug} />
            ) : null}
          </aside>
        </section>

        {event.checkoutEmbedUrl ? (
          <EventCheckout checkoutEmbedUrl={event.checkoutEmbedUrl} />
        ) : event.checkoutUnavailable ? (
          <EventCheckoutUnavailable eventId={event.id} />
        ) : null}

        {event.status === "completed" ? (
          <section className="rounded-lg border border-border p-6">
            <div className="flex items-center gap-3">
              <Trophy aria-hidden="true" className="size-5 text-accent-warm" />
              <h2 className="font-heading text-2xl font-semibold text-foreground">Récap</h2>
            </div>
            {event.recapAvailable && event.recap ? (
              <Link
                className="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                href={`/actualites/${event.recap}`}
              >
                Lire le récap
              </Link>
            ) : (
              <p className="mt-4 leading-7 text-muted-foreground">
                Le récap public n&apos;est pas encore disponible.
              </p>
            )}
          </section>
        ) : null}

        <EventPhotoGallery event={event} />
      </article>
    </>
  );
}

function EventCheckoutUnavailable({ eventId }: { eventId: string }) {
  return (
    <section className="flex items-start gap-4 rounded-lg border border-border p-6" id="billetterie">
      <AlertTriangle aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-danger" />
      <div>
        <h2 className="font-heading text-2xl font-semibold text-foreground">
          Billetterie temporairement indisponible
        </h2>
        <p className="mt-2 text-sm leading-6 text-muted-foreground">
          Le formulaire HelloAsso ne peut pas etre charge pour le moment. Aucune inscription
          locale n&apos;est creee tant que le paiement n&apos;est pas disponible.
        </p>
        <Link
          className="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          href={`/evenements/${eventId}#billetterie`}
        >
          <RefreshCw aria-hidden="true" className="size-4" />
          Reessayer
        </Link>
      </div>
    </section>
  );
}

function EventHeroImage({ event }: { event: PublicEvent }) {
  return (
    <section aria-label="Image de couverture de l'événement" className="relative -mx-6 overflow-hidden md:-mx-12 lg:-mx-20">
      <div className="relative aspect-[21/9] min-h-56 bg-surface">
        {event.coverImageUrl ? (
          <Image
            alt=""
            aria-hidden="true"
            className="object-cover"
            fill
            priority
            sizes="100vw"
            src={event.coverImageUrl}
            unoptimized={event.coverImageUrl.startsWith("http://") || event.coverImageUrl.startsWith("https://")}
          />
        ) : (
          <div className="flex h-full items-center justify-center bg-[linear-gradient(135deg,color-mix(in_oklab,var(--color-surface)_85%,var(--color-accent)),var(--color-background))]">
            <ImageIcon aria-hidden="true" className="size-12 text-muted-foreground/45" />
          </div>
        )}
        <div className="absolute inset-0 bg-gradient-to-t from-background/75 via-background/10 to-transparent" />
      </div>
    </section>
  );
}

function EventPhotoGallery({ event }: { event: PublicEvent }) {
  const photos = event.status === "completed" ? (event.photoGallery ?? []).slice(0, 12) : [];

  if (photos.length < 2) {
    return null;
  }

  return (
    <section aria-labelledby="event-photos-heading" className="grid gap-6 border-t border-border pt-12">
      <div>
        <p className="mb-2 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text text-on-canvas">
          Galerie
        </p>
        <h2 className="font-heading text-3xl font-semibold text-foreground text-on-canvas" id="event-photos-heading">
          Photos
        </h2>
      </div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {photos.map((url, index) => (
          <div className="relative aspect-[4/3] overflow-hidden rounded-lg bg-surface" key={`${url}-${index}`}>
            <Image
              alt=""
              aria-hidden="true"
              className="object-cover"
              fill
              sizes="(max-width: 640px) 50vw, 33vw"
              src={url}
              unoptimized={url.startsWith("http://") || url.startsWith("https://")}
            />
            <div className="absolute inset-0 bg-gradient-to-t from-background/25 to-transparent" />
          </div>
        ))}
      </div>
    </section>
  );
}

function DetailCta({ cta }: { cta: NonNullable<DetailStatus["cta"]> }) {
  const className =
    "inline-flex min-h-12 items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover";

  if (cta.external) {
    return (
      <a className={className} href={cta.href} rel="noopener noreferrer" target="_blank">
        {cta.label}
      </a>
    );
  }

  return (
    <Link className={className} href={cta.href}>
      {cta.label}
    </Link>
  );
}

function DetailMeta({
  icon: Icon,
  label,
  children,
}: {
  icon: typeof CalendarDays;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="grid gap-1 sm:grid-cols-[10rem_1fr] sm:items-center">
      <dt className="inline-flex items-center gap-2 text-muted-foreground">
        <Icon aria-hidden="true" className="size-4 text-accent-text" />
        {label}
      </dt>
      <dd className="font-semibold text-foreground">{children}</dd>
    </div>
  );
}

function getEventStructuredData(event: PublicEvent, canonicalUrl: string) {
  return {
    "@context": "https://schema.org",
    "@type": "Event",
    name: event.title,
    description: event.description,
    url: canonicalUrl,
    startDate: event.dateIso,
    ...(event.endDateIso ? { endDate: event.endDateIso } : {}),
    eventStatus: schemaStatusMap[event.status],
    eventAttendanceMode: event.attendanceMode
      ? schemaAttendanceModeMap[event.attendanceMode]
      : "https://schema.org/MixedEventAttendanceMode",
    location: {
      "@type": "Place",
      name: event.location,
    },
    organizer: {
      "@type": "Organization",
      name: "ArchiLAN",
      url: env.appUrl,
    },
    ...(event.coverImageUrl ? { image: event.coverImageUrl } : {}),
  };
}
