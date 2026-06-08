import type { Metadata } from "next";

import { AdminWeeklyRunGameDetail } from "@/features/admin/admin-weekly-run-game-detail";

export const metadata: Metadata = {
  title: "Runs hebdomadaires du jeu",
  description: "Suivi des runs hebdomadaires et gestion des templates d'un jeu.",
  openGraph: {
    title: "Runs hebdomadaires du jeu - Administration ArchiLAN",
  },
};

type Props = {
  params: Promise<{ gameId: string }>;
};

export default async function AdminWeeklyRunGamePage({ params }: Props) {
  const { gameId } = await params;
  return <AdminWeeklyRunGameDetail gameId={gameId} />;
}
