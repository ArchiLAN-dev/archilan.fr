import Link from "next/link";
import { ArrowRight, MessageCircle, Radio, Trophy } from "lucide-react";

import { externalLinks } from "@/lib/external-links";
import type { PublicEvent } from "@/features/events/event-types";
import { CommunityHubStats } from "./community-hub-stats";
import { CommunityMembersPreview } from "./community-members-preview";
import { LeaderboardClient } from "./leaderboard-client";
import { MemberAvatar } from "./member-avatar";
import type { CommunityStats, LeaderboardResponse } from "./community-api";
import type { CommunityOverview, PlayingNowEntry, RecentAchievement } from "./community-overview-api";
import type { DirectoryRow } from "./community-directory-api";

type Props = {
  overview: CommunityOverview | null;
  stats: CommunityStats | null;
  leaderboard: LeaderboardResponse | null;
  leaderboardFetchedAt: number;
  events: Pick<PublicEvent, "id" | "title">[];
  memberPreview: DirectoryRow[];
};

/**
 * The /communaute hub (story 30.38): what the community *is*, for someone who has not joined it yet.
 *
 * Every section degrades on its own - a failed fetch hides its block rather than the page, and the two
 * live sections ("en jeu", "succès récents") are hidden when empty: an empty "personne ne joue" panel
 * says the opposite of what this page is for.
 */
export function CommunityHub({
  overview,
  stats,
  leaderboard,
  leaderboardFetchedAt,
  events,
  memberPreview,
}: Props) {
  const playing = overview?.playingNow ?? [];
  const achievements = overview?.recentAchievements ?? [];

  return (
    <div className="grid gap-16">
      <section className="grid gap-6">
        <header className="grid gap-2">
          <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">Communauté</p>
          <h1 className="font-heading text-3xl font-bold text-foreground md:text-4xl">
            La communauté ArchiLAN
          </h1>
          <p className="max-w-2xl text-muted-foreground">
            Des joueurs francophones qui explorent Archipelago ensemble : runs hebdomadaires, parties
            privées et LAN. Voici ce qui s&apos;y passe en ce moment.
          </p>
        </header>

        <CommunityHubStats
          checksDone={stats?.totalChecksDone ?? null}
          finishedSessions={stats?.totalFinishedSessions ?? null}
          goalsReached={stats?.totalGoalsReached ?? null}
          memberCount={overview?.memberCount ?? null}
        />
      </section>

      {playing.length > 0 ? (
        <section aria-labelledby="playing-now" className="grid gap-5 border-t border-border pt-12">
          <div className="flex items-center gap-3">
            <span aria-hidden className="size-2.5 animate-pulse rounded-full bg-emerald-400" />
            <h2 className="font-heading text-2xl font-bold text-foreground" id="playing-now">
              En jeu maintenant
            </h2>
          </div>
          <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
            {playing.map((entry) => (
              <li key={entry.slug}>
                <PlayingCard entry={entry} />
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <section aria-labelledby="leaderboards" className="grid gap-5 border-t border-border pt-12">
        <div className="grid gap-2">
          <h2 className="font-heading text-2xl font-bold text-foreground" id="leaderboards">
            Classements
          </h2>
          <p className="text-sm text-muted-foreground">
            Les meilleurs joueurs ArchiLAN, toutes sessions confondues.
          </p>
        </div>
        <LeaderboardClient
          events={events}
          initialData={leaderboard}
          initialDataFetchedAt={leaderboardFetchedAt}
        />
      </section>

      {achievements.length > 0 ? (
        <section aria-labelledby="recent-achievements" className="grid gap-5 border-t border-border pt-12">
          <div className="grid gap-2">
            <h2 className="font-heading text-2xl font-bold text-foreground" id="recent-achievements">
              Succès récemment débloqués
            </h2>
            <p className="text-sm text-muted-foreground">Les derniers hauts faits de la communauté.</p>
          </div>
          <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" role="list">
            {achievements.map((achievement) => (
              <li key={`${achievement.slug}-${achievement.achievementKey}`}>
                <AchievementCard achievement={achievement} />
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <section aria-labelledby="members" className="grid gap-5 border-t border-border pt-12">
        <div className="grid gap-2">
          <h2 className="font-heading text-2xl font-bold text-foreground" id="members">
            Membres
          </h2>
          <p className="text-sm text-muted-foreground">
            Chaque joueur a son profil : niveau, succès, historique de runs.
          </p>
        </div>
        <CommunityMembersPreview initialRows={memberPreview} />
      </section>

      <section aria-labelledby="join" className="grid gap-5 border-t border-border pt-12">
        <div className="grid gap-2">
          <h2 className="font-heading text-2xl font-bold text-foreground" id="join">
            Nous rejoindre
          </h2>
          <p className="text-sm text-muted-foreground">
            Le Discord est le point de rendez-vous : on y organise les runs et on y suit les parties.
          </p>
        </div>
        <div className="grid gap-4 md:grid-cols-3">
          <a
            className="card-glow rounded-lg border border-border p-6 transition-colors hover:border-accent"
            href={externalLinks.archilanDiscord}
            rel="noopener noreferrer"
            target="_blank"
          >
            <MessageCircle aria-hidden className="mb-4 size-7 text-accent-text" />
            <h3 className="font-heading text-lg font-semibold text-foreground">
              Discord ArchiLAN<span className="sr-only"> (nouvel onglet)</span>
            </h3>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">
              Rejoins les discussions, trouve des joueurs et suis les annonces.
            </p>
          </a>
          <a
            className="card-glow rounded-lg border border-border p-6 transition-colors hover:border-accent"
            href={externalLinks.twitch}
            rel="noopener noreferrer"
            target="_blank"
          >
            <Radio aria-hidden className="mb-4 size-7 text-accent-text" />
            <h3 className="font-heading text-lg font-semibold text-foreground">
              Chaîne Twitch<span className="sr-only"> (nouvel onglet)</span>
            </h3>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">
              Les événements et les runs commentées, en direct.
            </p>
          </a>
          <Link
            className="card-glow rounded-lg border border-border p-6 transition-colors hover:border-accent"
            href="/adhesion"
          >
            <Trophy aria-hidden className="mb-4 size-7 text-accent-text" />
            <h3 className="font-heading text-lg font-semibold text-foreground">Adhérer à l&apos;association</h3>
            <p className="mt-2 inline-flex items-center gap-1 text-sm leading-6 text-muted-foreground">
              Soutiens ArchiLAN et accède aux événements
              <ArrowRight aria-hidden className="size-4" />
            </p>
          </Link>
        </div>
      </section>
    </div>
  );
}

function PlayingCard({ entry }: { entry: PlayingNowEntry }) {
  const name = entry.displayName ?? entry.slug;

  return (
    <Link
      className="flex h-full items-center gap-3 rounded-lg border border-border bg-surface p-3 transition-colors hover:border-accent"
      href={`/joueurs/${entry.slug}`}
    >
      <span className="relative inline-flex size-10 shrink-0">
        <MemberAvatar avatarUrl={entry.avatarUrl} name={name} />
        <span
          aria-hidden
          className="absolute -bottom-0.5 -right-0.5 size-3 animate-pulse rounded-full border-2 border-surface bg-emerald-400"
        />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold text-foreground">{name}</span>
        {/* A private run withholds its game server-side; the presence itself stays public. */}
        <span className="block truncate text-xs text-muted-foreground">
          {entry.game !== null ? `joue à ${entry.game}` : "en jeu"}
        </span>
      </span>
    </Link>
  );
}

function AchievementCard({ achievement }: { achievement: RecentAchievement }) {
  const name = achievement.displayName ?? achievement.slug;

  return (
    <Link
      className="flex h-full items-start gap-3 rounded-lg border border-border bg-surface p-3 transition-colors hover:border-accent"
      href={`/joueurs/${achievement.slug}/succes`}
    >
      <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-accent/15">
        {achievement.imageUrl !== null ? (
          // eslint-disable-next-line @next/next/no-img-element -- presigned MinIO URL, not a statically known asset
          <img alt="" aria-hidden className="size-full object-cover" src={achievement.imageUrl} />
        ) : (
          <Trophy aria-hidden className="size-5 text-accent-text" />
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold text-foreground">{achievement.name}</span>
        <span className="block truncate text-xs text-muted-foreground">
          {name} · {formatUnlockDate(achievement.unlockedAt)}
        </span>
      </span>
    </Link>
  );
}

/**
 * An absolute date, not "il y a 2 h": this is server-rendered, and a relative label computed from the
 * server clock would drift against the reader's on hydration.
 */
function formatUnlockDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";

  return new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short" }).format(date);
}
