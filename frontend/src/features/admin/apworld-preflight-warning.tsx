type Props = {
  // What the generator reported on a passed test; empty for a clean pass.
  warning: string;
};

const ACCESSIBILITY = "Could not access required locations for accessibility check.";

/** The locations named after "Missing: [" in the generator's accessibility message. */
function missingLocations(warning: string): string[] {
  const match = /Missing: \[([^\]]*)\]/.exec(warning);
  if (match === null) return [];
  return match[1]
    .split(",")
    .map((name) => name.trim())
    .filter((name) => name !== "");
}

/**
 * Story 38.12 : le test est réussi, mais le générateur a signalé quelque chose. Cas connu : des emplacements
 * inatteignables avec les options testées. Le Launcher officiel génère quand même la partie (il ne fait
 * qu'avertir) ; on fait de même, sans le cacher : les objets placés là sont perdus pour le joueur.
 */
export function ApworldPreflightWarning({ warning }: Props) {
  if (warning === "") return null;

  const missing = warning.startsWith(ACCESSIBILITY) ? missingLocations(warning) : [];

  return (
    <div className="grid gap-1 rounded border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-foreground">
      {missing.length > 0 ? (
        <>
          <p className="font-semibold text-warning">
            {missing.length} emplacement{missing.length > 1 ? "s" : ""} inatteignable{missing.length > 1 ? "s" : ""} avec les
            options testées
          </p>
          <ul className="list-disc pl-5 font-mono">
            {missing.map((name) => (
              <li key={name}>{name}</li>
            ))}
          </ul>
          <p className="text-muted-foreground">
            Comme le Launcher officiel, la génération passe : la partie reste gagnable, mais les objets placés là sont
            perdus pour le joueur. À signaler à l&apos;auteur de l&apos;apworld.
          </p>
        </>
      ) : (
        <p>
          <span className="font-semibold text-warning">Avertissement du générateur :</span> {warning}
        </p>
      )}
    </div>
  );
}
