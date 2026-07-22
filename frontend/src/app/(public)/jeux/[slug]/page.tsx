import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { env } from "@/lib/env";
import { JsonLd } from "@/components/json-ld";
import { breadcrumbJsonLd } from "@/lib/structured-data";
import { getArchipelagoClient } from "@/features/games/archipelago-client-api";
import { GameDetail } from "@/features/games/game-detail";
import { getPublicGame } from "@/features/games/public-games-api";
import { markdownToPlainText } from "@/components/markdown/markdown-to-plain-text";

export const revalidate = 300; // ISR (story 34.4)

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
  // Metadata must never carry markdown syntax into <meta>/OG cards (story 10.10).
  const description =
    markdownToPlainText(game.description) || `${game.name} dans la bibliothèque Archipelago d'ArchiLAN.`;

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

  const client = await getArchipelagoClient();

  return (
    <>
      <JsonLd
        data={breadcrumbJsonLd([
          { name: "Accueil", path: "/" },
          { name: "Jeux", path: "/jeux" },
          { name: game.name, path: `/jeux/${game.slug}` },
        ])}
      />
      <GameDetail client={client} game={game} />
    </>
  );
}