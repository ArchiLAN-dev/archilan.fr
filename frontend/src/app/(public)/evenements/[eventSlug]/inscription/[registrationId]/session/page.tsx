import type { Metadata } from "next";
import { SessionConnectionGate } from "@/features/events/session-connection-gate";
import { HubLink } from "@/features/registrant-hub/hub-link";
import { PresenceCard } from "@/features/presence/presence-editor";
import { BroadcastConsentCard } from "@/features/streaming/broadcast-consent-card";

export const metadata: Metadata = {
  title: "Connexion à la session",
  description: "Retrouvez les informations de connexion à votre session Archipelago.",
  robots: { index: false, follow: false },
};

type SessionPageProps = {
  params: Promise<{ eventSlug: string; registrationId: string }>;
};

export default function SessionPage({ params }: SessionPageProps) {
  return (
    <div className="mx-auto max-w-content px-4 py-10 sm:px-6">
      {/* Story 53.7: back to the registrant space. */}
      <HubLink params={params} />
      <SessionConnectionGate params={params} />
      {/* Story 47.1: whether the ArchiLAN broadcast may show the member's stream. */}
      <BroadcastConsentCard params={params} />
      {/* Story 52.2: when the member is at the event. */}
      <PresenceCard params={params} />
    </div>
  );
}
