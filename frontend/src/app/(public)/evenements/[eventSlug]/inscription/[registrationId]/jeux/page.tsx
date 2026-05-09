import type { Metadata } from "next";
import { GameSelectionGate } from "@/features/events/game-selection-gate";

export const metadata: Metadata = {
  title: "Sélection des jeux",
  description: "Sélectionnez vos jeux Archipelago pour votre inscription à l'événement ArchiLAN.",
  robots: { index: false, follow: false },
};

type JeuxPageProps = {
  params: Promise<{ eventSlug: string; registrationId: string }>;
};

export default function JeuxPage({ params }: JeuxPageProps) {
  return (
    <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
      <GameSelectionGate params={params} />
    </div>
  );
}
