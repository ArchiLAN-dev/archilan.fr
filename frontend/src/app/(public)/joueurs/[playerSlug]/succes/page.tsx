import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { getPlayerAchievements } from "@/features/players/player-profile-api";
import { AchievementsCataloguePage } from "@/features/community/achievements-catalogue-page";

type Props = {
  params: Promise<{ playerSlug: string }>;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { playerSlug } = await params;
  const catalogue = await getPlayerAchievements(playerSlug);

  if (!catalogue) {
    return {
      title: "Succès — joueur introuvable",
      robots: { index: false, follow: false },
    };
  }

  const name = catalogue.displayName ?? catalogue.slug;
  return {
    title: `Succès de ${name} — ArchiLAN`,
    description: `Tous les succès du site et ceux débloqués par ${name}.`,
  };
}

export default async function PlayerAchievementsRoute({ params }: Props) {
  const { playerSlug } = await params;
  const catalogue = await getPlayerAchievements(playerSlug);

  if (!catalogue) {
    notFound();
  }

  return <AchievementsCataloguePage catalogue={catalogue} />;
}