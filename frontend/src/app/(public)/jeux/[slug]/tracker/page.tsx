import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { TrackerScreen } from "@/features/tracker/ui/tracker-screen";
import { TrackerTrial } from "@/features/tracker/ui/tracker-trial";
import { getPublicGame } from "@/features/games/public-games-api";
import { getGameTrackerPacks } from "@/features/trackers/tracker-packs-api";

/**
 * Story 54.5: the tracker of a game. The page only hands the packs to the screen, which runs the pack in the
 * browser. Out of the index: nothing on it is worth a search result.
 */
export const revalidate = 300;

type Props = {
  params: Promise<{ slug: string }>;
  searchParams: Promise<{
    pack?: string | string[];
    variant?: string | string[];
    slot?: string | string[];
    version?: string | string[];
    essai?: string | string[];
  }>;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const game = await getPublicGame(slug);
  const title = game ? `Tracker ${game.name}` : "Tracker";
  const description = game
    ? `Suivez votre partie de ${game.name} : objets, cartes et lieux accessibles, dans le navigateur.`
    : "Tracker de jeu dans le navigateur.";
  return {
    title,
    description,
    robots: { index: false, follow: true },
    openGraph: { title: `${title} | ArchiLAN`, description },
  };
}

function single(value: string | string[] | undefined): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

export default async function GameTrackerPage({ params, searchParams }: Props) {
  const { slug } = await params;
  const game = await getPublicGame(slug);
  if (!game) notFound();
  const query = await searchParams;
  // Story 54.8: an admin's trial reads the pack from the admin API in the browser (the server render is anonymous).
  if (single(query.essai) === "1") {
    return (
      <TrackerTrial
        gameId={game.id}
        gameName={game.name}
        gameSlug={game.slug}
        initialVariantUid={single(query.variant)}
        packId={single(query.pack)}
        versionId={single(query.version)}
      />
    );
  }
  const packs = await getGameTrackerPacks(game.id);
  if (packs.length === 0) notFound();

  return (
    <TrackerScreen
      gameName={game.name}
      gameSlug={game.slug}
      initialPackId={single(query.pack)}
      initialVariantUid={single(query.variant)}
      packs={packs}
      slotRef={single(query.slot)}
    />
  );
}
