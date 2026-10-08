import Link from "next/link";
import { ChevronRight, EyeOff, Gift, Library, Sparkles } from "lucide-react";

import type { AchievementCollectionProgress, CatalogueAchievement } from "@/features/players/player-profile-api";
import { AchievementCard } from "./achievement-card";

/** Story 30.52: one section of the catalogue per collection, then « Autres succès ». */
export type CatalogueSection = { collection: AchievementCollectionProgress | null; achievements: CatalogueAchievement[] };

/**
 * The catalogue split into its collections, in the admin's order, each with its achievements in the admin's order;
 * the achievements of no collection last, unlocked first (most recent), then locked.
 */
export function catalogueSections(achievements: CatalogueAchievement[], collections: AchievementCollectionProgress[]): CatalogueSection[] {
  const known = new Set(collections.map((c) => c.id));
  const sections: CatalogueSection[] = collections
    .map((collection) => ({ collection, achievements: achievements.filter((a) => a.collectionId === collection.id) }))
    .filter((section) => section.achievements.length > 0);
  const others = achievements
    .filter((a) => a.collectionId == null || !known.has(a.collectionId))
    .sort((a, b) => (a.unlocked !== b.unlocked ? Number(b.unlocked) - Number(a.unlocked) : (b.unlockedAt ?? "").localeCompare(a.unlockedAt ?? "")));
  if (others.length > 0) sections.push({ collection: null, achievements: others });
  return sections;
}

/** « Récompense : Cadre « Mains de l'Envie » · 50 pelles », or nothing. */
export function collectionRewardText(collection: Pick<AchievementCollectionProgress, "reward" | "pelles">): string | null {
  const parts = [collection.reward, collection.pelles > 0 ? `${collection.pelles} ${collection.pelles > 1 ? "pelles" : "pelle"}` : null].filter((p) => p !== null);
  return parts.length > 0 ? parts.join(" · ") : null;
}

export function CollectionProgressBar({ collection }: { collection: Pick<AchievementCollectionProgress, "unlocked" | "total" | "complete" | "name"> }) {
  const percent = collection.total > 0 ? Math.round((collection.unlocked / collection.total) * 100) : 0;
  return (
    <div
      aria-label={`${collection.name} : ${collection.unlocked} sur ${collection.total}`}
      aria-valuemax={collection.total}
      aria-valuemin={0}
      aria-valuenow={collection.unlocked}
      className="h-1.5 overflow-hidden rounded-full bg-surface-2"
      role="progressbar"
    >
      <div className={`h-full rounded-full ${collection.complete ? "bg-amber-400" : "bg-accent"}`} style={{ width: `${percent}%` }} />
    </div>
  );
}

function CollectionImage({ collection, size }: { collection: AchievementCollectionProgress; size: "sm" | "lg" }) {
  const box = size === "lg" ? "size-14" : "size-9";
  if (collection.imageUrl) {
    // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
    return <img alt="" aria-hidden="true" className={`${box} shrink-0 rounded-lg object-cover`} src={collection.imageUrl} />;
  }
  return (
    <span aria-hidden className={`${box} inline-flex shrink-0 items-center justify-center rounded-lg ${collection.complete ? "bg-amber-400/15 text-amber-300" : "bg-accent/10 text-accent-text"}`}>
      <Library className={size === "lg" ? "size-6" : "size-4"} />
    </span>
  );
}

function CollectionBadges({ collection }: { collection: AchievementCollectionProgress }) {
  return (
    <>
      {collection.secret ? (
        <span className="inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
          <EyeOff aria-hidden className="size-3" /> Secrète
        </span>
      ) : null}
      {collection.complete ? (
        <span className="inline-flex items-center gap-1 rounded-full bg-amber-400/15 px-2 py-0.5 text-[11px] font-bold text-amber-300">
          <Sparkles aria-hidden className="size-3" /> Collection complète
        </span>
      ) : null}
    </>
  );
}

/** A section of the « Tous les succès » page: the collection's header and progress, then its achievements. */
export function CatalogueSectionView({ section }: { section: CatalogueSection }) {
  const { collection, achievements } = section;
  const grid = (
    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
      {achievements.map((achievement) => (
        <AchievementCard achievement={achievement} key={achievement.key} rarity={achievement.rarity} />
      ))}
    </ul>
  );
  if (collection === null) {
    return (
      <section aria-label="Autres succès" className="grid gap-3">
        <h2 className="font-heading text-lg font-semibold text-foreground">Autres succès</h2>
        {grid}
      </section>
    );
  }

  const reward = collectionRewardText(collection);
  return (
    <section aria-label={collection.name} className={`grid gap-4 rounded-lg border p-4 sm:p-5 ${collection.complete ? "border-amber-400/50 bg-amber-400/5" : "border-border bg-surface"}`}>
      <header className="flex items-start gap-4">
        <CollectionImage collection={collection} size="lg" />
        <div className="grid min-w-0 flex-1 gap-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="font-heading text-lg font-semibold text-foreground">{collection.name}</h2>
            <CollectionBadges collection={collection} />
            <span className="ml-auto text-sm font-semibold tabular-nums text-foreground">
              {collection.unlocked} / {collection.total}
            </span>
          </div>
          {collection.description !== "" ? <p className="text-sm text-muted-foreground">{collection.description}</p> : null}
          <CollectionProgressBar collection={collection} />
          {reward !== null ? (
            <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
              <Gift aria-hidden className="size-3.5" />
              {collection.complete ? "Gagné" : "À la clé"} : {reward}
            </p>
          ) : null}
        </div>
      </header>
      {grid}
    </section>
  );
}

/** The profile's started collections under its achievement count (the three most advanced). */
export function ProfileCollections({ slug, collections }: { slug: string; collections: AchievementCollectionProgress[] }) {
  if (collections.length === 0) return null;
  return (
    <ul aria-label="Collections" className="grid gap-2 sm:grid-cols-3" role="list">
      {collections.map((collection) => (
        <li key={collection.id}>
          <Link
            className={`flex items-center gap-3 rounded-lg border p-3 transition-colors hover:border-accent/60 ${collection.complete ? "border-amber-400/50 bg-amber-400/5" : "border-border bg-surface"}`}
            href={`/joueurs/${slug}/succes`}
          >
            <CollectionImage collection={collection} size="sm" />
            <span className="grid min-w-0 flex-1 gap-1">
              <span className="flex items-center justify-between gap-2 text-sm">
                <span className="truncate font-semibold text-foreground">{collection.name}</span>
                <span className="shrink-0 tabular-nums text-muted-foreground">
                  {collection.unlocked}/{collection.total}
                </span>
              </span>
              <CollectionProgressBar collection={collection} />
            </span>
            <ChevronRight aria-hidden className="size-4 shrink-0 text-muted-foreground" />
          </Link>
        </li>
      ))}
    </ul>
  );
}
