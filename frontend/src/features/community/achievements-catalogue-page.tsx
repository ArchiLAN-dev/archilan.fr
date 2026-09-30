import Link from "next/link";
import { ArrowLeft } from "lucide-react";

import type { PlayerAchievementsCatalogue } from "@/features/players/player-profile-api";
import { AchievementCard } from "./achievement-card";
import { AvatarImage } from "./avatar-image";
import type { ImageFraming } from "@/features/community/image-framing";

function CatalogueAvatar({ avatarUrl, animatedUrl = null, framing = null, name }: { avatarUrl: string | null; animatedUrl?: string | null; framing?: ImageFraming | null; name: string }) {
  if (avatarUrl !== null) {
    return (
      <AvatarImage
        animatedSrc={animatedUrl}
        className="size-12 shrink-0 rounded-full bg-surface object-cover"
        framing={framing}
        src={avatarUrl}
      />
    );
  }

  return (
    <div className="flex size-12 shrink-0 items-center justify-center rounded-full bg-accent/20 text-sm font-semibold uppercase text-accent-text">
      {name.slice(0, 2)}
    </div>
  );
}

/** The full « Tous les succès » catalogue for a player: every achievement with this player's state +
 * rarity. Unlocked first (most recent), then locked. */
export function AchievementsCataloguePage({ catalogue }: { catalogue: PlayerAchievementsCatalogue }) {
  const name = catalogue.displayName ?? catalogue.slug;
  const unlockedCount = catalogue.achievements.filter((a) => a.unlocked).length;

  const sorted = [...catalogue.achievements].sort((a, b) => {
    if (a.unlocked !== b.unlocked) return Number(b.unlocked) - Number(a.unlocked);
    return (b.unlockedAt ?? "").localeCompare(a.unlockedAt ?? "");
  });

  return (
    <article className="mx-auto grid max-w-content gap-6">
      <Link
        className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
        href={`/joueurs/${catalogue.slug}`}
      >
        <ArrowLeft aria-hidden className="size-3.5" />
        Retour au profil
      </Link>

      <header className="flex items-center gap-4 rounded-lg border border-border bg-surface p-5">
        <CatalogueAvatar animatedUrl={catalogue.avatarAnimatedUrl} avatarUrl={catalogue.avatarUrl} framing={catalogue.avatarFraming} name={name} />
        <div className="min-w-0">
          <h1 className="truncate font-heading text-2xl font-bold text-foreground">
            Succès de {name}
          </h1>
          <p className="mt-0.5 text-sm text-muted-foreground">
            {unlockedCount} / {catalogue.achievements.length} débloqués
          </p>
        </div>
      </header>

      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
        {sorted.map((achievement) => (
          <AchievementCard achievement={achievement} key={achievement.key} rarity={achievement.rarity} />
        ))}
      </ul>
    </article>
  );
}