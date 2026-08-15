import type { Metadata } from "next";
import { env } from "@/lib/env";
import { getSessionRecap } from "@/features/recap/recap-api.server";
import { getSessionFeed } from "@/features/recap/feed-api.server";
import { PrivateRecapFallback } from "@/features/recap/private-recap-fallback";
import { SessionRecapView } from "@/features/recap/session-recap-page";

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
    // The SSR fetch above can't authenticate the owner/participants of a private run - their session
    // cookie is host-bound to the API subdomain and never reaches this frontend-host request. A null
    // recap is therefore not necessarily "no access": retry in the browser, where the cookie is sent to
    // the API directly, so an authenticated owner/participant loads their private recap (story 32.5).
    return <PrivateRecapFallback sessionId={sessionId} />;
  }

  return <SessionRecapView feed={feed} recap={recap} />;
}
