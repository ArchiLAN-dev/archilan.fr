import Link from "next/link";
import { ArrowLeft } from "lucide-react";

import type { PlayerAchievementsCatalogue } from "@/features/players/player-profile-api";
import { CatalogueSectionView, catalogueSections } from "./achievement-collections";
import { MemberAvatar } from "./member-avatar";

/** The full « Tous les succès » catalogue for a player: every achievement with this player's state +
 * rarity. Story 30.52: one section per collection with the player's progress, then « Autres succès » (unlocked first,
 * most recent, then locked). */
export function AchievementsCataloguePage({ catalogue }: { catalogue: PlayerAchievementsCatalogue }) {
  const name = catalogue.displayName ?? catalogue.slug;
  const unlockedCount = catalogue.achievements.filter((a) => a.unlocked).length;

  const sections = catalogueSections(catalogue.achievements, catalogue.collections);

  return (
    <article className="mx-auto grid max-w-content gap-6">
      <Link
        className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
        href={`/joueurs/${catalogue.slug}`}
      >
        <ArrowLeft aria-hidden className="size-3.5" />
        Retour au profil
      </Link>
      {/* Story 30.55: the stats page, the other half of what the site knows of a player. */}
      <Link
        className="-mt-4 inline-flex w-fit items-center gap-1 text-sm text-accent-text hover:underline"
        href={`/joueurs/${catalogue.slug}/stats`}
      >
        Voir ses stats
      </Link>

      <header className="flex items-center gap-4 rounded-lg border border-border bg-surface p-5">
        <MemberAvatar
          avatarAnimatedUrl={catalogue.avatarAnimatedUrl}
          avatarUrl={catalogue.avatarUrl}
          frame={catalogue.avatarFrame}
          framing={catalogue.avatarFraming}
          name={name}
          size={48}
        />
        <div className="min-w-0">
          <h1 className="truncate font-heading text-2xl font-bold text-foreground">
            Succès de {name}
          </h1>
          <p className="mt-0.5 text-sm text-muted-foreground">
            {unlockedCount} / {catalogue.achievements.length} débloqués
          </p>
        </div>
      </header>

      {sections.map((section) => (
        <CatalogueSectionView key={section.collection?.id ?? "others"} section={section} slug={catalogue.slug} />
      ))}
    </article>
  );
}