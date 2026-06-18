import { BadgeCheck, ShieldCheck } from "lucide-react";
import type { ReactElement, ReactNode } from "react";
import Link from "next/link";
import type {
  PlayerHistory,
  PlayerProfile,
  ProfileCustomization as ProfileCustomizationData,
  ProfilePresence,
  RunHistoryEntry,
} from "./player-profile-api";
import { ProfileAvatar } from "./profile-avatar";
import { ProfileRelationshipActions } from "@/features/community/profile-relationship-actions";
import { ProfileActivity } from "@/features/community/community-activity";
import { ProfileAchievements } from "@/features/community/profile-achievements";
import { ProfileComments } from "@/features/community/profile-comments";
import { ProfileBanner } from "@/features/community/profile-banner";
import { resolveLinkType } from "@/features/community/social-links";

export function PlayerProfilePage({
  profile,
  history,
}: {
  profile: PlayerProfile;
  history: PlayerHistory | null;
}) {
  const displayName = profile.displayName ?? profile.slug;
  const entries = history?.data ?? [];
  const historyError = history === null;

  return (
    <article className="mx-auto w-full max-w-4xl grid gap-12">
      <header className="overflow-hidden rounded-2xl border border-border bg-surface">
        <ProfileBanner className="h-28 sm:h-36" presetKey={profile.customization?.bannerPreset ?? "default"} />

        {/* z-10 keeps the overlapping content above the positioned banner. */}
        <div className="relative z-10 grid gap-5 px-5 pb-6 sm:px-8">
          {/* Identity row: the avatar AND the name straddle the banner's bottom edge (≈ half on it). */}
          <div className="-mt-12 flex items-center gap-4 sm:-mt-14">
            <ProfileAvatar avatarUrl={profile.avatarUrl} frame={profile.customization?.avatarFrame ?? null} name={displayName} />
            <h1 className="font-heading text-3xl font-bold leading-tight text-foreground [text-shadow:0_2px_6px_rgba(0,0,0,0.7)] md:text-4xl">
              {displayName}
            </h1>
          </div>

          {/* Details sit on the surface below — readable, off the bright banner. */}
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="grid gap-2">
              <div className="flex flex-wrap items-center gap-2">
                <span className="inline-flex items-center rounded-full border border-accent/40 bg-accent/10 px-2.5 py-0.5 text-xs font-semibold text-accent-text">
                  Niv. {profile.level.level}
                </span>
                {profile.badges.admin ? (
                  <span className="inline-flex items-center gap-1 rounded-full border border-amber-500/40 bg-amber-500/10 px-2.5 py-0.5 text-xs font-semibold text-amber-400">
                    <ShieldCheck aria-hidden className="size-3.5" /> Admin
                  </span>
                ) : null}
                {profile.badges.member ? (
                  <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/40 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-400">
                    <BadgeCheck aria-hidden className="size-3.5" /> Adhérent
                  </span>
                ) : null}
                {profile.presence.playing ? <PresenceBadge presence={profile.presence} /> : null}
                {profile.customization?.pronouns ? (
                  <span className="rounded-full border border-border bg-background px-2 py-0.5 text-xs text-muted-foreground">
                    {profile.customization.pronouns}
                  </span>
                ) : null}
              </div>
              {profile.customization?.tagline ? (
                <p className="text-sm italic text-foreground/80">{profile.customization.tagline}</p>
              ) : null}
              <p className="text-sm text-muted-foreground">
                Membre depuis{" "}
                <time dateTime={profile.joinedAt}>{formatDate(profile.joinedAt)}</time>
              </p>
              <div className="w-full max-w-sm">
                <LevelBar level={profile.level} />
              </div>
              {profile.customization && profile.customization.socialLinks.length > 0 ? (
                <SocialLinkIcons links={profile.customization.socialLinks} />
              ) : null}
            </div>
            <ProfileRelationshipActions slug={profile.slug} />
          </div>

          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatCard label="Runs" value={String(profile.stats.runsParticipated)} />
            <StatCard label="Objectifs" value={String(profile.stats.goalCompletions)} />
            <StatCard label="Checks" value={String(profile.stats.totalChecksDone)} />
            <StatCard
              label="Taux de complétion"
              value={
                profile.stats.runsParticipated > 0
                  ? `${Math.round(profile.stats.goalCompletionRate * 100)}%`
                  : "-"
              }
            />
          </div>
        </div>
      </header>

      {profile.customization && profile.customization.showcaseLayout.length > 0 ? (
        <ProfileShowcase
          entries={entries}
          favorites={profile.customization.favoriteGames}
          layout={profile.customization.showcaseLayout}
        />
      ) : null}

      {profile.customization ? <ProfileCustomization customization={profile.customization} /> : null}

      {profile.achievements.length > 0 ? <ProfileAchievements achievements={profile.achievements} /> : null}

      <ProfileActivity slug={profile.slug} />

      <ProfileComments slug={profile.slug} />

      <section aria-labelledby="history-heading" className="grid gap-4">
        <h2 className="font-heading text-xl font-semibold text-foreground" id="history-heading">
          Historique des runs
        </h2>

        {historyError ? (
          <p className="text-muted-foreground">
            L&apos;historique est temporairement indisponible.
          </p>
        ) : entries.length === 0 ? (
          <p className="text-muted-foreground">Aucune run terminée pour l&apos;instant.</p>
        ) : (
          <>
            <div className="grid gap-2">
              {entries.map((entry) => (
                <RunHistoryRow entry={entry} key={`${entry.sessionId}-${entry.game}`} />
              ))}
            </div>
            {history !== null && history.meta.total > entries.length ? (
              <p className="text-xs text-muted-foreground text-center">
                Affichage des {entries.length} dernières runs ({history.meta.total} au total)
              </p>
            ) : null}
          </>
        )}
      </section>
    </article>
  );
}

function ProfileShowcase({
  layout,
  favorites,
  entries,
}: {
  layout: string[];
  favorites: ProfileCustomizationData["favoriteGames"];
  entries: RunHistoryEntry[];
}) {
  const bestRuns = [...entries]
    .filter((e) => !e.isInvalidated)
    .sort((a, b) => b.checksDone - a.checksDone)
    .slice(0, 3);
  const mostPlayed = topGames(entries);

  const widgets = layout
    .map((key) => renderShowcaseWidget(key, favorites, bestRuns, mostPlayed))
    .filter((w): w is ReactElement => w !== null);

  if (widgets.length === 0) return null;

  return (
    <section className="grid gap-6 rounded-2xl border border-border bg-surface/40 p-5">
      <h2 className="font-heading text-lg font-semibold text-foreground">Vitrine</h2>
      {widgets}
    </section>
  );
}

function renderShowcaseWidget(
  key: string,
  favorites: ProfileCustomizationData["favoriteGames"],
  bestRuns: RunHistoryEntry[],
  mostPlayed: { game: string; count: number }[],
): ReactElement | null {
  if (key === "favorite_games" && favorites.length > 0) {
    return (
      <ShowcaseBlock key={key} title="Jeux favoris">
        <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6" role="list">
          {favorites.map((game) => (
            <li key={game.id}>
              <Link className="group grid gap-1.5 text-center" href={`/jeux/${game.slug}`}>
                <span className="block aspect-[3/4] overflow-hidden rounded border border-border bg-surface">
                  {game.coverImageUrl ? (
                    // eslint-disable-next-line @next/next/no-img-element -- external IGDB cover
                    <img alt={game.name} className="h-full w-full object-cover object-top" src={game.coverImageUrl} />
                  ) : (
                    <span className="flex h-full w-full items-center justify-center text-xs font-semibold text-muted-foreground">
                      {game.name.slice(0, 2).toUpperCase()}
                    </span>
                  )}
                </span>
                <span className="line-clamp-2 text-xs text-muted-foreground group-hover:text-foreground">{game.name}</span>
              </Link>
            </li>
          ))}
        </ul>
      </ShowcaseBlock>
    );
  }

  if (key === "best_runs" && bestRuns.length > 0) {
    return (
      <ShowcaseBlock key={key} title="Meilleures runs">
        <ul className="grid gap-2" role="list">
          {bestRuns.map((entry) => (
            <li
              className="flex items-center justify-between gap-3 rounded border border-border bg-background px-3 py-2 text-sm"
              key={`${entry.sessionId}-${entry.game}`}
            >
              <span className="min-w-0 truncate text-foreground">{entry.game}</span>
              <span className="shrink-0 text-muted-foreground">
                <span className="font-semibold text-foreground">{entry.checksDone}</span> checks
              </span>
            </li>
          ))}
        </ul>
      </ShowcaseBlock>
    );
  }

  if (key === "most_played" && mostPlayed.length > 0) {
    return (
      <ShowcaseBlock key={key} title="Les plus joués">
        <ul className="grid gap-2" role="list">
          {mostPlayed.map((row) => (
            <li
              className="flex items-center justify-between gap-3 rounded border border-border bg-background px-3 py-2 text-sm"
              key={row.game}
            >
              <span className="min-w-0 truncate text-foreground">{row.game}</span>
              <span className="shrink-0 text-muted-foreground">
                <span className="font-semibold text-foreground">{row.count}</span> partie{row.count > 1 ? "s" : ""}
              </span>
            </li>
          ))}
        </ul>
      </ShowcaseBlock>
    );
  }

  return null;
}

function ShowcaseBlock({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="grid gap-3">
      <h3 className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">{title}</h3>
      {children}
    </div>
  );
}

function topGames(entries: RunHistoryEntry[]): { game: string; count: number }[] {
  const counts = new Map<string, number>();
  for (const entry of entries) {
    if (entry.game) counts.set(entry.game, (counts.get(entry.game) ?? 0) + 1);
  }
  return [...counts.entries()]
    .map(([game, count]) => ({ game, count }))
    .sort((a, b) => b.count - a.count)
    .slice(0, 5);
}

function LevelBar({ level }: { level: PlayerProfile["level"] }) {
  const pct = level.xpForNextLevel > 0 ? Math.round((level.xpIntoLevel / level.xpForNextLevel) * 100) : 0;
  return (
    <div className="mt-1 grid max-w-xs gap-1">
      <div className="flex justify-between text-[11px] text-muted-foreground">
        <span>Niveau {level.level}</span>
        <span className="tabular-nums">
          {level.xpIntoLevel} / {level.xpForNextLevel} XP
        </span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-surface">
        <div className="h-full rounded-full bg-accent" style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

function ProfileCustomization({ customization }: { customization: ProfileCustomizationData }) {
  const { bio } = customization;
  // Favorite games render as a Vitrine block (ProfileShowcase); social links live in the header card.
  // This section now holds only the "À propos" text.
  if (!bio) return null;

  return (
    <section className="grid gap-2">
      <h2 className="font-heading text-lg font-semibold text-foreground">À propos</h2>
      <p className="whitespace-pre-line text-sm leading-6 text-muted-foreground">{bio}</p>
    </section>
  );
}

function SocialLinkIcons({ links }: { links: ProfileCustomizationData["socialLinks"] }) {
  return (
    <ul className="flex flex-wrap items-center gap-1.5 pt-0.5" role="list">
      {links.map((link) => {
        const type = resolveLinkType(link.label);
        const Icon = type.icon;
        const name = type.key === "other" ? link.label || "Lien" : type.label;
        return (
          <li key={link.url}>
            <a
              aria-label={name}
              className="inline-flex size-8 items-center justify-center rounded-full border border-border bg-background text-muted-foreground transition-colors hover:border-accent hover:text-accent-text"
              href={link.url}
              rel="nofollow noopener noreferrer"
              target="_blank"
              title={name}
            >
              <Icon aria-hidden className="size-4" />
            </a>
          </li>
        );
      })}
    </ul>
  );
}

function PresenceBadge({ presence }: { presence: ProfilePresence }) {
  const label = presence.game ? `En jeu · ${presence.game}` : "En jeu";
  const dot = <span aria-hidden className="size-1.5 animate-pulse rounded-full bg-emerald-400" />;
  const className =
    "inline-flex items-center gap-1.5 rounded-full border border-emerald-500/40 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-300";

  if (presence.sessionId) {
    return (
      <Link className={`${className} hover:bg-emerald-500/20`} href={`/runs/${presence.sessionId}`}>
        {dot} {label}
      </Link>
    );
  }

  return (
    <span className={className}>
      {dot} {label}
    </span>
  );
}

function StatCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="card-glow rounded-lg border border-border bg-background p-4 text-center">
      <p className="font-heading text-2xl font-bold text-foreground">{value}</p>
      <p className="mt-1 text-xs text-muted-foreground">{label}</p>
    </div>
  );
}

function RunHistoryRow({ entry }: { entry: RunHistoryEntry }) {
  const muted = entry.isInvalidated;

  return (
    <Link
      className={`grid gap-3 rounded-lg border p-4 transition-colors hover:border-accent sm:grid-cols-[1fr_auto] ${
        muted ? "border-border/60 bg-surface/60" : "border-border bg-surface"
      }`}
      href={`/runs/${entry.sessionId}/resultats`}
    >
      <div className="grid gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <span
            className={`font-semibold ${muted ? "text-muted-foreground" : "text-foreground"}`}
          >
            {entry.eventName}
          </span>
          <StatusBadge entry={entry} />
        </div>

        <p className={`text-sm ${muted ? "text-muted-foreground/70" : "text-muted-foreground"}`}>
          {entry.game}
          {entry.finishedAt ? (
            <>
              {" · "}
              <time dateTime={entry.finishedAt}>{formatDate(entry.finishedAt)}</time>
            </>
          ) : null}
        </p>
      </div>

      <dl
        className={`flex gap-4 text-sm sm:flex-col sm:items-end sm:gap-1 ${
          muted ? "text-muted-foreground/70" : "text-muted-foreground"
        }`}
      >
        <div className="flex gap-1">
          <dt className="sr-only">Checks</dt>
          <dd>
            <span className="font-semibold text-foreground">{entry.checksDone}</span> checks
          </dd>
        </div>
        <div className="flex gap-1">
          <dt className="sr-only">Items reçus</dt>
          <dd>
            <span className="font-semibold text-foreground">{entry.itemsReceived}</span> items
          </dd>
        </div>
      </dl>
    </Link>
  );
}

function StatusBadge({ entry }: { entry: RunHistoryEntry }) {
  if (entry.isInvalidated) {
    return (
      <span className="shrink-0 rounded border border-amber-500/50 px-2 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
        Forfait
      </span>
    );
  }

  if (entry.goalReachedAt !== null) {
    return (
      <span className="shrink-0 rounded border border-success/50 px-2 py-0.5 text-xs font-semibold text-success">
        Objectif atteint
      </span>
    );
  }

  return (
    <span className="shrink-0 rounded border border-muted-foreground/40 px-2 py-0.5 text-xs font-semibold text-muted-foreground">
      Incomplet
    </span>
  );
}

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(new Date(iso));
}
