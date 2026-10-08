import type { ReactNode } from "react";

import { InfoHint } from "@/components/ui/info-hint";

/**
 * Story 33.28: the game notes repeated across the private run, event and weekly pages, written once - the short
 * sentence visible, the technical why behind the « i ».
 */

const IDLE_WHY = "Le serveur d'une partie s'arrête après une période sans activité, pour libérer les ressources du serveur.";

/** Above the connection fields: every value is hidden, copying still works. */
export function StreamMaskedNote({ className = "text-xs text-muted-foreground" }: { className?: string }) {
  return <p className={className}>Valeurs masquées pour le stream : la copie marche sans les afficher.</p>;
}

/** A weekly attempt whose server went to sleep. */
export function ServerPausedNote({ className }: { className?: string }) {
  return (
    <InfoHint className={className} hint={IDLE_WHY}>
      Le serveur est en pause : relance-le pour reprendre ta partie là où elle s&apos;était arrêtée.
    </InfoHint>
  );
}

/** A generated game: its games and configs are frozen. `children` says what can no longer change. */
export function GeneratedLockNote({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <InfoHint className={className} hint="Une reprise rejoue toujours la partie déjà générée, telle quelle.">
      {children}
    </InfoHint>
  );
}
