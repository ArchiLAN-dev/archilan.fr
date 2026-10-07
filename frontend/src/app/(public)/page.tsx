import Link from "next/link";
import Image from "next/image";
import { ArrowRight } from "lucide-react";
import { externalLinks } from "@/lib/external-links";
import { fetchDiscordStats } from "@/features/discord/discord-api";
import { DiscordJoinButton } from "@/features/discord/discord-promo";
import { ConsentGatedTwitchEmbed } from "@/features/streaming/consent-gated-twitch-embed";
import { LiveStreamHeading } from "@/features/streaming/live-stream-heading";
import { getPublicEvents } from "@/features/events/public-events-api";
import { getPublicPosts } from "@/features/content/public-posts-api";
import { getHomeCommunityStats, getHomeRecaps, getHomeWeeklyRuns, newestFirst } from "@/features/home/home-api";
import {
  HomeAssociation,
  HomeCommunity,
  HomeConcept,
  HomeLan,
  HomeNews,
  HomeNow,
  HomeRecaps,
  HomeStartSteps,
  HomeThisWeek,
} from "@/features/home/home-sections";
import { buildPageMetadata } from "@/lib/seo";

// Keyword skeleton (Archipelago-in-France cluster); final copy is 34.6's scope.
export const metadata = buildPageMetadata({
  absoluteTitle: true,
  title: "ArchiLAN - Événements Archipelago multiworld en France",
  description:
    "ArchiLAN organise des événements Archipelago en France : LAN parties coopératives, runs hebdomadaires multiworld randomizer et une communauté gaming francophone.",
  path: "/",
});

// ISR: static shell revalidated every 5 min (story 34.4). Realtime widgets on this page
// (Twitch live badge, community stats) are client components and fetch their own fresh data.
export const revalidate = 300;

export default async function Home() {
  // Story 34.9: everything the sections show, read in parallel and kept five minutes.
  const [{ upcoming, past: pastAnyOrder }, discord, weeklyRuns, stats, posts] = await Promise.all([
    getPublicEvents(),
    fetchDiscordStats(),
    getHomeWeeklyRuns(),
    getHomeCommunityStats(),
    getPublicPosts(),
  ]);
  const past = newestFirst(pastAnyOrder);
  const recaps = await getHomeRecaps(past);
  const covers = weeklyRuns.flatMap((run) => (run.coverImageUrl ? [{ name: run.gameName, url: run.coverImageUrl }] : [])).slice(0, 3);

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
              <span className="bg-linear-to-r from-foreground via-foreground to-accent-text bg-clip-text text-transparent">
                Joue pour toi, gagne pour tous.
              </span>
            </h1>
            <p className="mt-6 max-w-xl text-lg leading-8 text-muted-foreground">
              ArchiLAN organise des événements Archipelago multiworld en France : un
              randomizer coopératif qui connecte plusieurs jeux en une seule aventure.
            </p>
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <Link
                className="btn-glow inline-flex min-h-12 items-center justify-center gap-2 rounded bg-accent px-6 font-semibold text-white transition-all duration-300 hover:bg-accent-hover"
                href="#commencer"
              >
                Je commence
                <ArrowRight aria-hidden="true" className="size-4" />
              </Link>
              <DiscordJoinButton size="lg" />
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
            <div className="mt-6">
              <HomeNow discord={discord} nextEvent={upcoming[0] ?? null} weeklyRuns={weeklyRuns.length} />
            </div>
          </div>
        </div>
      </section>

      <div className="grid gap-24">

      <HomeConcept covers={covers} />
      <HomeStartSteps />
      <HomeThisWeek runs={weeklyRuns} />
      <HomeLan past={past} upcoming={upcoming} />
      <HomeRecaps recaps={recaps} />
      <HomeCommunity discord={discord} stats={stats} />
      <HomeAssociation />
      <HomeNews posts={posts} />

      {/* ArchiLAN en direct */}
      <section aria-labelledby="live-stream-heading" className="border-t border-border pt-12">
        <LiveStreamHeading />
        <ConsentGatedTwitchEmbed />
      </section>

      </div>

    </div>
  );
}
