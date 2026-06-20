import { Lock, Trophy } from "lucide-react";

import type { Achievement } from "@/features/players/player-profile-api";

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(new Date(iso));
}

/**
 * The "Succès" section. Renders server-fetched achievements (locked + unlocked). Kudos on achievements were
 * dropped (they added no value); kudos remain on the activity feed / runs.
 */
export function ProfileAchievements({ achievements }: { achievements: Achievement[] }) {
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
