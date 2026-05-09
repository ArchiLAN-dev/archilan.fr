import type { Metadata } from "next";
import { SlotYamlGate } from "@/features/events/slot-yaml-gate";

export const metadata: Metadata = {
  title: "Configuration des options",
  description: "Configurez les options du randomizer pour ce jeu.",
  robots: { index: false, follow: false },
};

type SlotPageProps = {
  params: Promise<{ eventSlug: string; registrationId: string; slotId: string }>;
};

export default function SlotYamlPage({ params }: SlotPageProps) {
  return (
    <div className="px-4 py-10 sm:px-6">
      <SlotYamlGate params={params} />
    </div>
  );
}
