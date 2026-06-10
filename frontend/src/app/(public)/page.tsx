import Link from "next/link";
import Image from "next/image";
import { ArrowRight, CalendarDays, MessageCircle, Radio } from "lucide-react";
import { externalLinks } from "@/lib/external-links";
import { ConsentGatedTwitchEmbed } from "@/features/streaming/consent-gated-twitch-embed";
import { LiveStreamHeading } from "@/features/streaming/live-stream-heading";
import { CommunityStatsWidget } from "@/features/community/community-stats-widget";
import { EventCard, EventsEmptyState } from "@/features/events/event-card";
import { getPublicEvents } from "@/features/events/public-events-api";

export const dynamic = "force-dynamic";

export default async function Home() {
  const { upcoming, past } = await getPublicEvents();

  return (
    <div className="grid gap-24">

      {/* Hero - bannière empilée sur mobile, photo immersive superposée sur desktop */}
      <section className="relative -mx-6 -mt-16 flex flex-col md:-mx-12 lg:-mx-20 lg:min-h-[88vh] lg:flex-row lg:items-end">
        {/* Image : bannière en haut sur mobile, fond plein écran sous le texte sur desktop */}
        <div
          className="relative h-72 w-full shrink-0 sm:h-96 lg:absolute lg:inset-0 lg:h-auto"
          style={{ maskImage: "linear-gradient(to bottom, black 87%, transparent 100%)" }}
        >
          <Image
            alt="Participant jouant lors d'un événement ArchiLAN"
            className="object-cover object-center"
            fill
            priority
            sizes="100vw"
            src="/images/events/lan-photo-1.webp"
          />
          {/* Dégradés de lisibilité du texte superposé - desktop uniquement */}
          <div className="absolute inset-0 hidden bg-gradient-to-r from-background from-8% via-background/55 via-50% to-transparent lg:block" />
          <div className="absolute inset-0 hidden bg-gradient-to-b from-background/45 via-transparent to-background/70 lg:block" />
        </div>

        <div className="relative z-10 w-full px-6 pb-12 pt-8 md:px-12 lg:px-20 lg:pb-20 lg:pt-0">
          <div className="max-w-2xl">
            <Image
              alt="Logo ArchiLAN"
              className="mb-8 size-16"
              height={64}
              src="/images/logo.webp"
              width={64}
            />
            <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em]" style={{ color: "var(--color-special)" }}>
              Association Archipelago en France
            </p>
            <h1 className="font-heading text-4xl font-bold leading-tight md:text-5xl">
              <span className="bg-gradient-to-r from-foreground via-foreground to-accent bg-clip-text text-transparent">
                Un item de ton jeu.<br />
                Le monde entier.
              </span>
            </h1>
            <p className="mt-6 max-w-xl text-lg leading-8 text-muted-foreground">
              ArchiLAN organise des événements autour d&apos;Archipelago, un mode
              coopératif qui connecte plusieurs jeux en une seule aventure.
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <Link
                className="btn-glow inline-flex min-h-12 items-center justify-center gap-2 rounded bg-accent px-6 font-semibold text-white transition-all duration-300 hover:bg-accent-hover"
                href="/evenements"
              >
                Voir les événements
                <ArrowRight aria-hidden="true" className="size-4" />
              </Link>
              <a
                aria-label="Ouvrir Twitch ArchiLAN (nouvel onglet)"
                className="inline-flex min-h-12 items-center justify-center gap-2 rounded border border-border bg-background/60 px-6 font-semibold text-foreground backdrop-blur-sm transition-colors hover:border-accent"
                href={externalLinks.twitch}
                rel="noopener noreferrer"
                target="_blank"
              >
                Suivre sur Twitch
              </a>
            </div>
          </div>
        </div>
      </section>

      <div className="grid gap-24">

      {/* C'est quoi Archipelago ? */}
      <section aria-labelledby="archipelago-heading">
        <div className="max-w-2xl">
          <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text text-on-canvas">
            Le concept
          </p>
          <h2 className="font-heading text-3xl font-bold text-foreground md:text-4xl text-on-canvas" id="archipelago-heading">
            C&apos;est quoi Archipelago&nbsp;?
          </h2>
          <p className="mt-4 text-lg leading-8 text-muted-foreground text-on-canvas">
            Imagine trouver une clé dans Hollow Knight qui ouvre une porte dans
            Stardew Valley pour une autre personne. Archipelago mélange les
            objets de plusieurs jeux pour transformer une soirée LAN en chasse
            au trésor coopérative.
          </p>
        </div>

        <div className="mt-10 grid gap-4 sm:grid-cols-3">
          <div className="card-glow rounded-lg border border-border p-6">
            <p className="font-heading text-lg font-semibold text-foreground">Multiworld</p>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">
              Les objets de chaque jeu sont redistribués entre les joueurs. Tu
              progresses chez toi, tu aides les autres à progresser chez eux.
            </p>
          </div>
          <div className="card-glow rounded-lg border border-border p-6">
            <p className="font-heading text-lg font-semibold text-foreground">Coopératif</p>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">
              Pas de compétition - tout le monde gagne ensemble. Chaque
              découverte peut débloquer la progression d&apos;un coéquipier.
            </p>
          </div>
          <div className="card-glow rounded-lg border border-border p-6">
            <p className="font-heading text-lg font-semibold text-foreground">Communauté</p>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">
              Ambiance chill et entraide. ArchiLAN c&apos;est aussi des streams,
              des replays, et une communauté francophone qui grandit.
            </p>
          </div>
        </div>
      </section>

      {/* Événements - dynamiques (à venir + passés) */}
      <section aria-labelledby="events-heading" className="border-t border-border pt-12">
        <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="mb-2 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text text-on-canvas">
              Agenda
            </p>
            <h2 className="font-heading text-3xl font-bold text-foreground text-on-canvas" id="events-heading">
              Nos événements
            </h2>
          </div>
          <Link
            className="shrink-0 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground text-on-canvas"
            href="/evenements"
          >
            Voir tous les événements →
          </Link>
        </div>

        {upcoming.length === 0 && past.length === 0 ? (
          <EventsEmptyState />
        ) : (
          <div className="grid gap-12">
            {upcoming.length > 0 ? (
              <div>
                <h3 className="mb-5 font-heading text-xl font-semibold text-foreground text-on-canvas">
                  À venir
                </h3>
                <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                  {upcoming.slice(0, 3).map((event) => (
                    <EventCard event={event} key={event.id} />
                  ))}
                </div>
              </div>
            ) : null}

            {past.length > 0 ? (
              <div>
                <h3 className="mb-5 font-heading text-xl font-semibold text-foreground text-on-canvas">
                  Passés
                </h3>
                <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                  {past.slice(0, 3).map((event) => (
                    <EventCard event={event} key={event.id} />
                  ))}
                </div>
              </div>
            ) : null}
          </div>
        )}
      </section>

      {/* Stats communautaires */}
      <CommunityStatsWidget />

      {/* Actions communautaires */}
      <section aria-labelledby="community-actions" className="grid gap-6 border-t border-border pt-12 md:grid-cols-3">
        <h2 className="sr-only" id="community-actions">
          Actions communautaires
        </h2>
        <Link
          className="card-glow rounded-lg border border-border p-6"
          href="/evenements"
        >
          <CalendarDays aria-hidden="true" className="mb-5 size-7 text-accent-text" />
          <h3 className="font-heading text-xl font-semibold">Événements à venir</h3>
          <p className="mt-3 text-sm leading-6 text-muted-foreground">
            Consulte les prochaines sessions dès leur publication.
          </p>
        </Link>
        <a
          className="card-glow rounded-lg border border-border p-6"
          href={externalLinks.twitch}
          rel="noopener noreferrer"
          target="_blank"
        >
          <Radio aria-hidden="true" className="mb-5 size-7 text-accent-text" />
          <h3 className="font-heading text-xl font-semibold">
            Chaîne Twitch ArchiLAN<span className="sr-only"> (nouvel onglet)</span>
          </h3>
          <p className="mt-3 text-sm leading-6 text-muted-foreground">
            Ouvre la chaîne quand aucun live intégré n&apos;est actif.
          </p>
        </a>
        <a
          className="card-glow rounded-lg border border-border p-6"
          href={externalLinks.archilanDiscord}
          rel="noopener noreferrer"
          target="_blank"
        >
          <MessageCircle aria-hidden="true" className="mb-5 size-7 text-accent-text" />
          <h3 className="font-heading text-xl font-semibold">
            Discord ArchiLAN<span className="sr-only"> (nouvel onglet)</span>
          </h3>
          <p className="mt-3 text-sm leading-6 text-muted-foreground">
            Rejoins la communauté pour suivre l&apos;activité et préparer les sessions.
          </p>
        </a>
      </section>

      {/* ArchiLAN en direct */}
      <section aria-labelledby="live-stream-heading" className="border-t border-border pt-12">
        <LiveStreamHeading />
        <ConsentGatedTwitchEmbed />
      </section>

      </div>

    </div>
  );
}
