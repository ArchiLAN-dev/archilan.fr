import type { Metadata } from "next";
import { Download, ExternalLink } from "lucide-react";

import { getArchipelagoClient } from "@/features/games/archipelago-client-api";
import { getArchipelagoGuide } from "@/features/games/archipelago-guide-api";
import { InstallStepsView } from "@/features/games/install-steps-view";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Installer Archipelago",
  description: "Le guide pour installer le client Archipelago et préparer ta première partie multiworld.",
  openGraph: {
    title: "Installer Archipelago | ArchiLAN",
    description: "Le guide pour installer le client Archipelago et préparer ta première partie multiworld.",
  },
};

export default async function ArchipelagoGuidePage() {
  const [steps, client] = await Promise.all([getArchipelagoGuide(), getArchipelagoClient()]);

  return (
    <article className="mx-auto grid w-full max-w-3xl gap-10">
      <header className="grid gap-4 border-b border-border pb-8">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Aide</p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
          Installer Archipelago
        </h1>
        <p className="text-lg leading-8 text-muted-foreground">
          Les étapes pour installer le client Archipelago et préparer ta première partie. Chaque jeu a
          ensuite son propre tutoriel sur sa fiche.
        </p>
      </header>

      {client !== null ? (
        <section className="grid gap-3 rounded-lg border border-accent/40 bg-accent/5 p-5">
          <h2 className="font-heading text-lg font-semibold text-foreground">Client Archipelago</h2>
          <p className="text-sm text-muted-foreground">
            Version recommandée :{" "}
            <span className="rounded border border-border bg-surface px-2 py-0.5 font-mono text-xs text-foreground">
              {client.version}
            </span>
          </p>
          <a
            className="inline-flex w-fit min-h-10 items-center gap-2 rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href={client.downloadUrl}
            rel="noopener noreferrer"
            target="_blank"
          >
            <Download aria-hidden="true" className="size-4" />
            Télécharger le client
            <ExternalLink aria-hidden="true" className="size-3.5" />
          </a>
        </section>
      ) : null}

      {steps.length > 0 ? (
        <section className="grid gap-5">
          <h2 className="font-heading text-2xl font-semibold text-foreground">Étapes</h2>
          <InstallStepsView steps={steps} storageKey="archipelago-guide" />
        </section>
      ) : (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          Le guide détaillé arrive bientôt. En attendant, télécharge le client ci-dessus et consulte la
          fiche de chaque jeu pour son installation.
        </p>
      )}
    </article>
  );
}