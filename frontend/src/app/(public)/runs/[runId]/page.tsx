import type { Metadata } from "next";
import { PersonalRunDetailPage } from "@/features/personal-runs/personal-run-detail-page";

export const metadata: Metadata = {
  title: "Ma partie",
  description: "Détail et gestion d'une partie Archipelago personnelle.",
};

export default function RunDetailPage({ params }: { params: Promise<{ runId: string }> }) {
  return <PersonalRunDetailPage params={params} />;
}
