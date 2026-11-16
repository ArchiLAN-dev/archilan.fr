import { ExternalLink, MapIcon } from "lucide-react";

import type { GameTrackerPack } from "./tracker-packs-api";

type Props = { packs: GameTrackerPack[] };

/**
 * Story 54.2: the "Tracker disponible" block of a game page. It credits the authors of the pack that opens by default,
 * and names the other packs offered. Opening the tracker comes with story 54.5.
 */
export function GameTrackerCredits({ packs }: Props) {
  const [main, ...others] = packs;
  if (main === undefined) return null;

  return (
    <section className="grid gap-4 rounded-lg border border-border bg-surface p-5" aria-labelledby="tracker-disponible">
      <h2 className="flex items-center gap-2 font-heading text-2xl font-semibold text-foreground" id="tracker-disponible">
        <MapIcon aria-hidden="true" className="size-5 text-accent-text" />
        Tracker disponible
      </h2>
      <PackCredits pack={main} />
      {others.length > 0 ? (
        <div className="grid gap-2 border-t border-border pt-3">
          <p className="text-sm text-muted-foreground">Autres packs proposés</p>
          <ul className="grid gap-3">
            {others.map((pack) => (
              <li key={pack.id}>
                <PackCredits pack={pack} />
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </section>
  );
}

function PackCredits({ pack }: { pack: GameTrackerPack }) {
  return (
    <div className="grid gap-1 text-sm">
      <p className="font-semibold text-foreground">
        {pack.name}
        {pack.version.packageVersion !== null ? (
          <span className="ml-2 font-mono font-normal text-muted-foreground">v{pack.version.packageVersion}</span>
        ) : null}
      </p>
      <p className="text-muted-foreground">
        {pack.author !== null ? `par ${pack.author}` : "Auteur non précisé"}
        {pack.licenseSpdx !== null ? ` · licence ${pack.licenseSpdx}` : null}
        {pack.hasApVariant ? " · suivi automatique Archipelago" : null}
      </p>
      <a
        className="inline-flex w-fit items-center gap-1 text-accent-text underline-offset-2 hover:underline"
        href={pack.sourceUrl}
        rel="noopener noreferrer"
        target="_blank"
      >
        Source du pack
        <ExternalLink aria-hidden="true" className="size-3.5" />
      </a>
    </div>
  );
}
