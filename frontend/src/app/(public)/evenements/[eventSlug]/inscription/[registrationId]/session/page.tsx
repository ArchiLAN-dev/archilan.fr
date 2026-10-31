import type { Metadata } from "next";
import { SessionConnectionGate } from "@/features/events/session-connection-gate";
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
      <SessionConnectionGate params={params} />
      {/* Story 47.1: whether the ArchiLAN broadcast may show the member's stream. */}
      <BroadcastConsentCard params={params} />
    </div>
  );
}
