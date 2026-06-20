import Link from "next/link";
import { BookOpen } from "lucide-react";

/**
 * Post-selection install nudge (story 31.4): once a player has picked games, point them to each
 * game's tutorial plus the generic "Installer Archipelago" guide. Pure (no hooks) - usable from
 * server or client components.
 */
export function InstallNudge({ games }: { games: { name: string; slug: string }[] }) {
  const distinct = Array.from(new Map(games.map((g) => [g.slug, g])).values());
  if (distinct.length === 0) {
    return null;
  }

  return (
    <section className="grid gap-3 rounded-lg border border-accent/40 bg-accent/5 p-5">
      <div className="flex items-center gap-2">
        <BookOpen aria-hidden="true" className="size-5 text-accent-text" />
        <h2 className="font-heading text-lg font-semibold text-foreground">Prépare l&apos;installation</h2>
      </div>
      <p className="text-sm leading-6 text-muted-foreground">
        Voici comment installer les jeux que tu as sélectionnés. Commence par{" "}
        <Link className="font-medium text-accent-text hover:underline" href="/aide/archipelago">
          installer Archipelago
        </Link>
        , puis suis le tutoriel de chaque jeu :
      </p>
      <ul className="flex flex-wrap gap-2">
        {distinct.map((game) => (
          <li key={game.slug}>
            <Link
              className="inline-flex items-center rounded border border-border bg-surface px-3 py-1.5 text-sm font-medium text-foreground transition-colors hover:border-accent"
              href={`/jeux/${game.slug}`}
            >
              {game.name}
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
