import Link from "next/link";
import { ChevronRight } from "lucide-react";

import type { Achievement, AchievementStats } from "@/features/players/player-profile-api";
import { AchievementCard } from "./achievement-card";

/**
 * The "Succès" section on the profile card. Shows only the most recently unlocked achievements + the
 * unlocked/total count and a link to the full catalogue page (story 30.31). Kudos on achievements were
 * dropped (they added no value); kudos remain on the activity feed / runs.
 */
export function ProfileAchievements({
  slug,
  achievements,
  stats,
}: {
  slug: string;
  achievements: Achievement[];
  stats: AchievementStats;
}) {
  const remaining = Math.max(0, stats.total - stats.unlocked);

  return (
    <section className="grid gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-heading text-lg font-semibold text-foreground">
          Succès{" "}
          <span className="text-sm font-normal text-muted-foreground">
            ({stats.unlocked}/{stats.total})
          </span>
        </h2>
        <Link
          className="inline-flex items-center gap-1 text-sm font-semibold text-accent-text transition-colors hover:text-accent-text-hover"
          href={`/joueurs/${slug}/succes`}
        >
          Voir tous les succès
          <ChevronRight aria-hidden className="size-4" />
        </Link>
      </div>

      {achievements.length > 0 ? (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
          {achievements.map((achievement) => (
            <AchievementCard achievement={achievement} key={achievement.key} />
          ))}
        </ul>
      ) : (
        <p className="text-sm text-muted-foreground">Aucun succès débloqué pour l&apos;instant.</p>
      )}

      {remaining > 0 ? (
        <p className="text-xs text-muted-foreground">
          +{remaining} succès à débloquer
        </p>
      ) : null}
    </section>
  );
}