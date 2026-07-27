import type { Metadata } from "next";
import { env } from "@/lib/env";
import { getSessionRecap } from "@/features/recap/recap-api";
import { getSessionFeed } from "@/features/recap/feed-api";
import { SessionRecapNotFound, SessionRecapView } from "@/features/recap/session-recap-page";

type Props = {
  params: Promise<{ sessionId: string }>;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { sessionId } = await params;
  const recap = await getSessionRecap(sessionId);

  if (!recap) {
    return {
      title: "Récap indisponible",
      robots: { index: false, follow: false },
    };
  }

  const title = `Récap - ${recap.eventName}`;
  const description = `Revivez la partie : graphe des échanges d'objets, podium et temps forts de ${recap.eventName}.`;

  return {
    title,
    description,
    metadataBase: new URL(env.appUrl),
    openGraph: {
      title: `${title} | ArchiLAN`,
      description,
      siteName: "ArchiLAN",
      type: "website",
      locale: "fr_FR",
    },
    twitter: {
      card: "summary_large_image",
      title: `${title} | ArchiLAN`,
      description,
    },
  };
}

export default async function SessionRecapRoute({ params }: Props) {
  const { sessionId } = await params;
  const [recap, feed] = await Promise.all([getSessionRecap(sessionId), getSessionFeed(sessionId)]);

  if (!recap) {
    return <SessionRecapNotFound />;
  }

  return <SessionRecapView feed={feed} recap={recap} />;
}
