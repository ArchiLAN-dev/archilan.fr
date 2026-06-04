import type { Metadata } from "next";
import { WeeklyRunGameClientPage } from "@/features/weekly-runs/weekly-run-game-client";

export const metadata: Metadata = {
  title: "Runs hebdomadaires",
  description: "Consulte les runs hebdomadaires pour ce jeu et compare ton temps avec les autres membres.",
  openGraph: {
    title: "Runs hebdomadaires - ArchiLAN",
  },
};

type Props = {
  params: Promise<{ gameSlug: string }>;
};

export default function WeeklyRunGamePage({ params }: Props) {
  return (
    <div className="mx-auto max-w-2xl">
      <WeeklyRunGameClientPage params={params} />
    </div>
  );
}
