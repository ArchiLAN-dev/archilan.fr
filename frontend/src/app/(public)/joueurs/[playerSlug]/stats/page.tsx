import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { getPlayerStats } from "@/features/players/player-stats-api";
import { PlayerStatsPageView } from "@/features/players/player-stats-page";

type Props = {
  params: Promise<{ playerSlug: string }>;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { playerSlug } = await params;
  const stats = await getPlayerStats(playerSlug);

  if (!stats) {
    return {
      title: "Stats - joueur introuvable",
      robots: { index: false, follow: false },
    };
  }

  const name = stats.displayName ?? stats.slug;
  const title = `Stats de ${name}`;
  const description = `Parties, temps de jeu, morts DeathLink, jeux joués : tout ce qu'ArchiLAN suit du parcours de ${name}.`;
  return {
    title,
    description,
    openGraph: { title, description },
  };
}

export default async function PlayerStatsRoute({ params }: Props) {
  const { playerSlug } = await params;
  const stats = await getPlayerStats(playerSlug);

  if (!stats) {
    notFound();
  }

  return <PlayerStatsPageView stats={stats} />;
}
