import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { env } from "@/lib/env";
import { getPlayerHistory, getPlayerProfile } from "@/features/players/player-profile-api";
import { PlayerProfilePage } from "@/features/players/player-profile-page";

type Props = {
  params: Promise<{ playerSlug: string }>;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { playerSlug } = await params;
  const profile = await getPlayerProfile(playerSlug);

  if (!profile) {
    return {
      title: "Joueur introuvable",
      robots: { index: false, follow: false },
    };
  }

  const displayName = profile.displayName ?? profile.slug;
  const title = `${displayName} - Profil ArchiLAN`;

  return {
    title,
    metadataBase: new URL(env.appUrl),
    openGraph: {
      title,
      siteName: "ArchiLAN",
      type: "profile",
      locale: "fr_FR",
    },
    twitter: {
      card: "summary",
      title,
    },
  };
}

export default async function PlayerProfileRoute({ params }: Props) {
  const { playerSlug } = await params;
  const [profile, history] = await Promise.all([
    getPlayerProfile(playerSlug),
    getPlayerHistory(playerSlug),
  ]);

  if (!profile) {
    notFound();
  }

  return <PlayerProfilePage history={history} profile={profile} />;
}
