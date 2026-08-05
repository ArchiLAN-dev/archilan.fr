import type { MetadataRoute } from "next";

import { getPublicPosts } from "@/features/content/public-posts-api";
import { getPublicEvents } from "@/features/events/public-events-api";
import { getAllPublicGames } from "@/features/games/public-games-api";
import { getPublicProfileSlugs } from "@/features/players/player-profile-api";
import { getEventRecapIndex } from "@/features/recap/recap-api";
import { slugify } from "@/features/weekly-runs/slugify";
import { fetchCurrentWeeklyRuns } from "@/features/weekly-runs/weekly-runs-api";
import { env } from "@/lib/env";

/**
 * Dynamic sitemap for the public site.
 *
 * Only indexable public routes appear - no admin/overlay/account/auth/registration or
 * meta-noindexed surface. URLs use the route the crawler actually visits (events are keyed
 * by id: the detail page has no slug yet - story 34.2 owns that).
 *
 * `lastModified` is set only where a real timestamp exists (posts' `publishedAtIso`); a
 * fabricated date (e.g. an event start date) is worse for crawl scheduling than none.
 *
 * The four fetchers each return `[]`/empty on failure and never throw, so if one API call
 * fails the sitemap still renders with the static routes plus whatever succeeded - it never
 * 500s. They use `cache: "no-store"`, so this route is request-time dynamic, matching the
 * site's current model; the ISR strategy is story 34.4's, not this one's.
 */
function absolute(path: string): string {
  return new URL(path, env.appUrl).toString();
}

const STATIC_ROUTES = [
  "/",
  "/evenements",
  "/actualites",
  "/jeux",
  "/runs-hebdo",
  "/communaute",
  "/joueurs",
  "/boutique",
  "/adhesion",
  "/aide/archipelago",
  "/cgu",
  "/cgv",
  "/mentions-legales",
  "/confidentialite",
];

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [events, posts, games, weeklyRuns, publicProfiles] = await Promise.all([
    getPublicEvents(),
    getPublicPosts(),
    getAllPublicGames(),
    fetchCurrentWeeklyRuns(),
    getPublicProfileSlugs(),
  ]);

  const staticEntries: MetadataRoute.Sitemap = STATIC_ROUTES.map((path) => ({ url: absolute(path) }));

  // Upcoming and past both stay published (completed events keep their recap).
  const eventEntries: MetadataRoute.Sitemap = [...events.upcoming, ...events.past].map((event) => ({
    url: absolute(`/evenements/${event.id}`),
  }));

  const postEntries: MetadataRoute.Sitemap = posts.map((post) => ({
    url: absolute(`/actualites/${post.slug}`),
    lastModified: post.publishedAtIso,
  }));

  const gameEntries: MetadataRoute.Sitemap = games.map((game) => ({
    url: absolute(`/jeux/${game.slug}`),
  }));

  // Weekly slugs rotate with the program and can repeat across runs of the same game - dedupe.
  const weeklySlugs = [...new Set(weeklyRuns.map((run) => slugify(run.gameName)))];
  const weeklyEntries: MetadataRoute.Sitemap = weeklySlugs.map((slug) => ({
    url: absolute(`/runs-hebdo/jeu/${slug}`),
  }));

  // Public session recaps (epic 32, stitched in after 34.1): every finished session of a past
  // public event has an indexable /parties/{id} page - the richest per-page content the site
  // produces. `finishedAt` is a real timestamp, so it can honestly drive `lastModified`.
  // Published personal runs stay out: they have no public enumeration (link-shared by design).
  // `getEventRecapIndex` returns [] on any failure, keeping the sitemap's never-500 contract.
  const recapIndexes = await Promise.all(events.past.map((event) => getEventRecapIndex(event.id)));
  const recapEntries: MetadataRoute.Sitemap = recapIndexes.flat().map((entry) => ({
    url: absolute(`/parties/${entry.sessionId}`),
    ...(entry.finishedAt !== null ? { lastModified: entry.finishedAt.slice(0, 10) } : {}),
  }));

  // Player profiles whose audience is "public" (story 34.8, product decision 2026-07-29): the
  // API enumerates exactly what an anonymous crawler can see - members/friends-only profiles
  // never appear, matching the profile page's own visibility gate. `updatedAt` is the profile
  // row's real timestamp.
  const profileEntries: MetadataRoute.Sitemap = publicProfiles.map((profile) => ({
    url: absolute(`/joueurs/${profile.slug}`),
    ...(profile.updatedAt !== "" ? { lastModified: profile.updatedAt.slice(0, 10) } : {}),
  }));

  return [...staticEntries, ...eventEntries, ...postEntries, ...gameEntries, ...weeklyEntries, ...recapEntries, ...profileEntries];
}
