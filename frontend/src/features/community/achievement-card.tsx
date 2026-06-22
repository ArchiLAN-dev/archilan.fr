import { Lock, Trophy, Users } from "lucide-react";

import type { Achievement, AchievementRarity } from "@/features/players/player-profile-api";

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(new Date(iso));
}

function rarityLabel(rarity: AchievementRarity): string {
  if (rarity.count === 0) return "Personne ne l'a encore";
  if (rarity.percent === null) return `${rarity.count} joueur${rarity.count > 1 ? "s" : ""}`;
  return `${rarity.percent} % des joueurs l'ont`;
}

/** A single achievement tile (unlocked = highlighted, locked = faded). Optional rarity badge for the
 * full catalogue page. */
export function AchievementCard({
  achievement,
  rarity,
}: {
  achievement: Achievement;
  rarity?: AchievementRarity;
}) {
  return (
    <li
      className={`flex items-start gap-3 rounded-lg border p-4 ${
        achievement.unlocked ? "border-accent/40 bg-accent/5" : "border-border bg-surface/60 opacity-70"
      }`}
    >
      {achievement.customImageUrl ? (
        // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
        <img
          alt=""
          aria-hidden="true"
          className="size-9 shrink-0 rounded-full object-cover"
          src={achievement.customImageUrl}
        />
      ) : (
        <span
          aria-hidden
          className={`flex size-9 shrink-0 items-center justify-center rounded-full ${
            achievement.unlocked ? "bg-accent/15 text-accent-text" : "bg-surface text-muted-foreground"
          }`}
        >
          {achievement.unlocked ? <Trophy className="size-4" /> : <Lock className="size-4" />}
        </span>
      )}
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
        {rarity ? (
          <p className="mt-1 inline-flex items-center gap-1 text-[11px] text-muted-foreground/80">
            <Users aria-hidden className="size-3" />
            {rarityLabel(rarity)}
          </p>
        ) : null}
      </div>
    </li>
  );
}