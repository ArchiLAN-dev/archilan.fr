import { Lock, Trophy, Users } from "lucide-react";

import type { Achievement, AchievementRarity } from "@/features/players/player-profile-api";
import { Markdown } from "@/components/markdown/markdown";

export function formatDate(iso: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(new Date(iso));
}

export function rarityLabel(rarity: AchievementRarity): string {
  if (rarity.count === 0) return "Personne ne l'a encore";
  if (rarity.percent === null) return `${rarity.count} joueur${rarity.count > 1 ? "s" : ""}`;
  return `${rarity.percent} % des joueurs l'ont`;
}

/** A single achievement tile (unlocked = highlighted, locked = faded). Optional rarity badge for the
 * full catalogue page. Story 30.53: with `onOpen`, the whole tile is a button that opens its details. */
export function AchievementCard({
  achievement,
  rarity,
  onOpen,
}: {
  achievement: Achievement;
  rarity?: AchievementRarity;
  onOpen?: () => void;
}) {
  const tile = `flex w-full items-start gap-3 rounded-lg border p-4 text-left ${
    achievement.unlocked ? "border-accent/40 bg-accent/5" : "border-border bg-surface/60 opacity-70"
  }`;
  if (onOpen) {
    return (
      <li className="flex">
        <button
          aria-haspopup="dialog"
          className={`${tile} transition-colors hover:border-accent/70 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60`}
          onClick={onOpen}
          type="button"
        >
          <AchievementTileContent achievement={achievement} rarity={rarity} />
        </button>
      </li>
    );
  }
  return (
    <li className={tile}>
      <AchievementTileContent achievement={achievement} rarity={rarity} />
    </li>
  );
}

function AchievementTileContent({ achievement, rarity }: { achievement: Achievement; rarity?: AchievementRarity }) {
  return (
    <>
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
        <p className="mt-0.5 text-xs text-muted-foreground">
          <Markdown inline>{achievement.description}</Markdown>
        </p>
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
    </>
  );
}