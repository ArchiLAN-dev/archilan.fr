import type { Metadata } from "next";

import { RunListingsBoard } from "@/features/personal-runs/run-listings";

// Story 43.17: signed-in members only, so kept out of the index.
export const metadata: Metadata = {
  title: "Parties qui cherchent des joueurs",
  description: "Les parties Archipelago des membres ArchiLAN qui cherchent encore des joueurs.",
  openGraph: { title: "Parties qui cherchent des joueurs" },
  robots: { index: false, follow: false },
};

export default function RunListingsPage() {
  return (
    <div className="mx-auto grid w-full max-w-content gap-6">
      <header className="grid gap-2">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">Communauté</p>
        <h1 className="font-heading text-3xl font-bold text-foreground">Parties qui cherchent des joueurs</h1>
        <p className="max-w-2xl text-muted-foreground">
          Des membres préparent une partie et cherchent du monde. Rejoins-en une : tu choisiras ton jeu sur la page de la partie.
        </p>
      </header>
      <RunListingsBoard />
    </div>
  );
}
