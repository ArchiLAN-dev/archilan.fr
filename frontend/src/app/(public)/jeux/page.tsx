import { buildPageMetadata } from "@/lib/seo";

import { GamesCatalog } from "@/features/games/games-catalog";
import { getAllPublicGames } from "@/features/games/public-games-api";
import { GameRequestSection } from "@/features/games/game-request-section";
import { GameContributionForm } from "@/features/games/game-contribution-form";

export const revalidate = 300; // ISR (story 34.4)

export const metadata = buildPageMetadata({
  title: "Jeux",
  description: "Bibliothèque de jeux Archipelago supportés par ArchiLAN.",
  path: "/jeux",
});

export default async function GamesPage() {
  const games = await getAllPublicGames();

  return (
    <div className="mx-auto w-full max-w-wide grid gap-16">
      <section>
        <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Bibliothèque ArchiLAN
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight md:text-5xl">
          Jeux compatibles Archipelago
        </h1>
        <p className="mt-5 max-w-2xl text-lg leading-8 text-muted-foreground">
          Le catalogue des jeux multiworld supportés dans nos événements et runs randomizer. Chacun a
          été intégré et testé par l&apos;équipe ArchiLAN.
        </p>
      </section>

      <GamesCatalog initialGames={games} />

      <GameRequestSection />

      <section className="grid gap-3">
        <h2 className="font-heading text-2xl font-semibold text-foreground">Proposer une doc</h2>
        <p className="max-w-2xl text-sm leading-6 text-muted-foreground">
          Tu sais installer un jeu qui n&apos;est pas encore listé ? Propose sa documentation, l&apos;équipe
          la relira avant publication.
        </p>
        <GameContributionForm mode="proposed" />
      </section>
    </div>
  );
}
