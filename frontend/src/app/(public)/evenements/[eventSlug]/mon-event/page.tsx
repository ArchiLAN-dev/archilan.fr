import type { Metadata } from "next";
import { cache } from "react";
import { notFound } from "next/navigation";

import { getPublicEvent } from "@/features/events/public-events-api";
import { RegistrantHub } from "@/features/registrant-hub/registrant-hub";

type RegistrantHubPageProps = {
  params: Promise<{ eventSlug: string }>;
};

// Same cache as the event page: only the public event is read on the server, the registrant's part is client-side
// (the SSR is anonymous, story 32.5).
export const revalidate = 300;

const loadEvent = cache(getPublicEvent);

export async function generateMetadata({ params }: RegistrantHubPageProps): Promise<Metadata> {
  const { eventSlug } = await params;
  const event = await loadEvent(eventSlug);
  const title = event === null ? "Mon event" : `Mon event - ${event.title}`;

  return {
    title,
    description: "Ton espace d'inscrit : ce qu'il te reste à faire, les infos pratiques et les outils de l'événement.",
    openGraph: { title: `${title} | ArchiLAN` },
    robots: { index: false, follow: false },
  };
}

export default async function RegistrantHubPage({ params }: RegistrantHubPageProps) {
  const { eventSlug } = await params;
  const event = await loadEvent(eventSlug);

  if (event === null) {
    notFound();
  }

  return (
    <div className="mx-auto w-full max-w-content px-4 py-10 sm:px-6">
      <RegistrantHub
        event={{
          id: event.id,
          slug: eventSlug,
          title: event.title,
          status: event.status,
          startsAt: event.dateIso,
          endsAt: event.endDateIso,
          location: event.location,
        }}
      />
    </div>
  );
}
