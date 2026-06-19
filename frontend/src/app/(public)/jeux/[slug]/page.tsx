import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { env } from "@/lib/env";
import { GameDetail } from "@/features/games/game-detail";
import { getPublicGame } from "@/features/games/public-games-api";

export const dynamic = "force-dynamic";

type GameDetailPageProps = {
  params: Promise<{ slug: string }>;
};

export async function generateMetadata({ params }: GameDetailPageProps): Promise<Metadata> {
  const { slug } = await params;
  const game = await getPublicGame(slug);

  if (!game) {
    return {
      title: "Jeu introuvable",
      robots: { index: false, follow: false },
    };
  }

  const canonicalPath = `/jeux/${game.slug}`;
  const description = game.description || `${game.name} dans la bibliothèque Archipelago d'ArchiLAN.`;

  return {
    title: game.name,
    description,
    metadataBase: new URL(env.appUrl),
    alternates: { canonical: canonicalPath },
    openGraph: {
      title: `${game.name} | ArchiLAN`,
      description,
      url: canonicalPath,
      siteName: "ArchiLAN",
      type: "website",
      locale: "fr_FR",
      ...(game.coverImageUrl
        ? { images: [{ url: game.coverImageUrl, alt: game.coverImageAlt || game.name }] }
        : {}),
    },
  };
}

export default async function GameDetailPage({ params }: GameDetailPageProps) {
  const { slug } = await params;
  const game = await getPublicGame(slug);

  if (!game) {
    notFound();
  }

  return <GameDetail game={game} />;
}