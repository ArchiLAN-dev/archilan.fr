import type { Metadata } from "next";
import { WeeklyRunsClientPage } from "@/features/weekly-runs/weekly-runs-client-page";

export const metadata: Metadata = {
  title: "Runs hebdomadaires",
  description: "Participe au run Archipelago de la semaine et suis le classement en temps réel.",
  openGraph: {
    title: "Runs hebdomadaires - ArchiLAN",
  },
};

export default function RunsHebdoPage() {
  return (
    <div className="mx-auto max-w-2xl">
      <header className="mb-8">
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
          Compétition hebdo
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
          Runs hebdomadaires
        </h1>
        <p className="mt-3 text-muted-foreground">
          Inscris-toi, lance ta partie et compare ton temps avec les autres membres.
        </p>
      </header>
      <WeeklyRunsClientPage />
    </div>
  );
}
