import { InfoHint } from "@/components/ui/info-hint";

/**
 * Story 33.28: the tracking notes shared by the run, slot and weekly pages - the short sentence visible, the
 * technical why behind the « i ».
 */

/** A seed generated elsewhere: no reachable checks, everything else works. */
export function ImportedSeedNote({ className }: { className?: string }) {
  return (
    <InfoHint
      className={className}
      hint={
        <>
          Savoir quels checks sont faisables demande de reconstruire le monde à partir des configurations des joueurs,
          que l&apos;archive d&apos;une seed importée ne contient pas.
        </>
      }
    >
      Checks faisables, sphères et détail des objets indisponibles sur une seed importée. Le reste fonctionne : checks
      faits, objets reçus, objectif, indices, fichiers et récap.
    </InfoHint>
  );
}

/** While the tracking is computed. */
export function ReachabilityComputingNote({ className }: { className?: string }) {
  return (
    <InfoHint
      className={className}
      hint="Sur une grosse partie, le premier calcul peut prendre une minute : le serveur reconstruit tout le multiworld avant de répondre."
    >
      Calcul du suivi en cours, la page se met à jour toute seule.
    </InfoHint>
  );
}
