import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { TrackerScreen } from "@/features/tracker/ui/tracker-screen";
import { getPublicGame } from "@/features/games/public-games-api";
import { getGameTrackerPacks } from "@/features/trackers/tracker-packs-api";

/**
 * Story 54.5: the tracker of a game. The page only hands the packs to the screen, which runs the pack in the
 * browser. Out of the index: nothing on it is worth a search result.
 */
export const revalidate = 300;

type Props = {
  params: Promise<{ slug: string }>;
  searchParams: Promise<{ pack?: string | string[]; variant?: string | string[]; slot?: string | string[] }>;
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
  const packs = await getGameTrackerPacks(game.id);
  if (packs.length === 0) notFound();
  const query = await searchParams;

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
