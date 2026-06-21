import type { Metadata } from "next";
import { PersonalRunParticipantDetailPage } from "@/features/personal-runs/personal-run-participant-detail-page";

export const metadata: Metadata = {
  title: "Configuration d'un joueur",
  description: "Jeux sélectionnés et configuration YAML d'un participant à la partie.",
};

export default function RunParticipantDetailRoute({
  params,
}: {
  params: Promise<{ runId: string; participantId: string }>;
}) {
  return <PersonalRunParticipantDetailPage params={params} />;
}