import type { Metadata } from "next";
import { PersonalRunGameSelectionPage } from "@/features/personal-runs/personal-run-game-selection-page";

export const metadata: Metadata = {
  title: "Sélection de jeux",
  description: "Choisis et configure tes jeux pour cette partie personnelle.",
};

export default function RunGameSelectionPage({ params }: { params: Promise<{ runId: string }> }) {
  return <PersonalRunGameSelectionPage params={params} />;
}
