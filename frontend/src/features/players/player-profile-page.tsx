import { Lock, Trophy } from "lucide-react";
import Link from "next/link";
import type {
  Achievement,
  PlayerHistory,
  PlayerProfile,
  ProfileCustomization as ProfileCustomizationData,
  RunHistoryEntry,
} from "./player-profile-api";
import { ProfileAvatar } from "./profile-avatar";

const BANNER_CLASSES: Record<string, string> = {
  default: "bg-gradient-to-r from-accent/30 via-accent/10 to-transparent",
  sunset: "bg-gradient-to-r from-orange-500/40 via-pink-500/20 to-transparent",
  forest: "bg-gradient-to-r from-emerald-600/40 via-emerald-400/15 to-transparent",
  arcade: "bg-gradient-to-r from-fuchsia-500/40 via-cyan-400/20 to-transparent",
  midnight: "bg-gradient-to-r from-indigo-800/50 via-indigo-500/20 to-transparent",
  aurora: "bg-gradient-to-r from-teal-400/40 via-violet-500/20 to-transparent",
};

function bannerClass(preset: string): string {
  return BANNER_CLASSES[preset] ?? BANNER_CLASSES.default;
}

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
        <div className={`h-28 sm:h-36 ${bannerClass(profile.customization?.bannerPreset ?? "default")}`} />

        <div className="grid gap-6 p-5 sm:p-8">
          <div className="-mt-16 flex flex-col gap-4 sm:-mt-20 sm:flex-row sm:items-end">
            <ProfileAvatar avatarUrl={profile.avatarUrl} name={displayName} />
            <div className="grid gap-1 pb-1">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-accent-text">
                Profil joueur
              </p>
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h1 className="font-heading text-3xl font-bold leading-tight text-foreground md:text-4xl">
                  {displayName}
                </h1>
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
            </div>
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

      {profile.customization ? <ProfileCustomization customization={profile.customization} /> : null}

      {profile.achievements.length > 0 ? <ProfileAchievements achievements={profile.achievements} /> : null}

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

function ProfileAchievements({ achievements }: { achievements: Achievement[] }) {
  const unlockedCount = achievements.filter((a) => a.unlocked).length;
  const sorted = [...achievements].sort((a, b) => Number(b.unlocked) - Number(a.unlocked));

  return (
    <section className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground">
        Succès{" "}
        <span className="text-sm font-normal text-muted-foreground">
          ({unlockedCount}/{achievements.length})
        </span>
      </h2>
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
        {sorted.map((achievement) => (
          <li
            className={`flex items-start gap-3 rounded-lg border p-4 ${
              achievement.unlocked ? "border-accent/40 bg-accent/5" : "border-border bg-surface/60 opacity-70"
            }`}
            key={achievement.key}
          >
            <span
              aria-hidden
              className={`flex size-9 shrink-0 items-center justify-center rounded-full ${
                achievement.unlocked ? "bg-accent/15 text-accent-text" : "bg-surface text-muted-foreground"
              }`}
            >
              {achievement.unlocked ? <Trophy className="size-4" /> : <Lock className="size-4" />}
            </span>
            <div className="min-w-0">
              <p className={`text-sm font-semibold ${achievement.unlocked ? "text-foreground" : "text-muted-foreground"}`}>
                {achievement.name}
              </p>
              <p className="mt-0.5 text-xs text-muted-foreground">{achievement.description}</p>
              {achievement.unlocked && achievement.unlockedAt ? (
                <p className="mt-1 text-[11px] text-accent-text">
                  Débloqué le <time dateTime={achievement.unlockedAt}>{formatDate(achievement.unlockedAt)}</time>
                </p>
              ) : null}
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}

function ProfileCustomization({ customization }: { customization: ProfileCustomizationData }) {
  const { bio, socialLinks, favoriteGames } = customization;
  if (!bio && socialLinks.length === 0 && favoriteGames.length === 0) return null;

  return (
    <section className="grid gap-8">
      {bio ? (
        <div className="grid gap-2">
          <h2 className="font-heading text-lg font-semibold text-foreground">À propos</h2>
          <p className="whitespace-pre-line text-sm leading-6 text-muted-foreground">{bio}</p>
        </div>
      ) : null}

      {favoriteGames.length > 0 ? (
        <div className="grid gap-3">
          <h2 className="font-heading text-lg font-semibold text-foreground">Jeux favoris</h2>
          <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6" role="list">
            {favoriteGames.map((game) => (
              <li key={game.id}>
                <Link
                  className="group grid gap-1.5 text-center"
                  href={`/jeux/${game.slug}`}
                >
                  <span className="block aspect-[3/4] overflow-hidden rounded border border-border bg-surface">
                    {game.coverImageUrl ? (
                      // eslint-disable-next-line @next/next/no-img-element -- external IGDB cover URL
                      <img
                        alt={game.name}
                        className="h-full w-full object-cover object-top transition-transform group-hover:scale-105"
                        src={game.coverImageUrl}
                      />
                    ) : (
                      <span className="flex h-full w-full items-center justify-center text-xs font-semibold text-muted-foreground">
                        {game.name.slice(0, 2).toUpperCase()}
                      </span>
                    )}
                  </span>
                  <span className="line-clamp-2 text-xs text-muted-foreground group-hover:text-foreground">
                    {game.name}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {socialLinks.length > 0 ? (
        <div className="grid gap-3">
          <h2 className="font-heading text-lg font-semibold text-foreground">Liens</h2>
          <ul className="flex flex-wrap gap-2" role="list">
            {socialLinks.map((link) => (
              <li key={link.url}>
                <a
                  className="inline-flex min-h-9 items-center rounded-full border border-border bg-surface px-3 text-sm font-medium text-foreground transition-colors hover:border-accent hover:text-accent-text"
                  href={link.url}
                  rel="nofollow noopener noreferrer"
                  target="_blank"
                >
                  {link.label}
                </a>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </section>
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
