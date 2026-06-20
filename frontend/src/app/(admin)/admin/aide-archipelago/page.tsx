import type { Metadata } from "next";

import { ArchipelagoClientSettings } from "@/features/admin/archipelago-client-settings";
import { ArchipelagoGuideSettings } from "@/features/admin/archipelago-guide-settings";

export const metadata: Metadata = {
  title: "Aide Archipelago",
};

export default function AideArchipelagoPage() {
  return (
    <section className="grid w-full min-w-0 grid-cols-1 gap-8 px-4 py-10">
      <header className="grid gap-1">
        <h1 className="font-heading text-2xl font-bold text-foreground">Aide Archipelago</h1>
        <p className="text-sm text-muted-foreground">
          Client recommandé et guide d&apos;installation générique affichés aux joueurs sur{" "}
          <span className="font-mono">/aide/archipelago</span>.
        </p>
      </header>

      <ArchipelagoClientSettings />
      <ArchipelagoGuideSettings />
    </section>
  );
}
