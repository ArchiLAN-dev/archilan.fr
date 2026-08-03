import type { PlayerRow } from "./build-player-rows";
import { formatDuration, formatSuperlativeValue } from "./recap-format";

/** What each superlative rewards - kept from the cards this table replaced, as the badge tooltip. */
const SUPERLATIVE_HINTS: Record<string, string> = {
  most_generous: "A envoyé le plus d'objets aux autres",
  biggest_hub: "A débloqué le plus de joueurs différents",
  first_to_goal: "Premier à atteindre son objectif",
  longest_road: "La plus longue route jusqu'au but",
};

/**
 * The comparative table of a run (story 32.16). Replaces both the four isolated superlative cards -
 * which gave a value with nothing to compare it to - and the podium list, which showed the same
 * players again a few hundred pixels below.
 *
 * "Envoyés" and "reçus" count exchanges with other players; a slot's own finds are the "gardés"
 * column. Same convention as the exchange diagram above and as the superlatives, deliberately.
 */
export function RecapPlayerTable({
  rows,
  colorBySlotName,
}: {
  rows: PlayerRow[];
  colorBySlotName: Map<string, string>;
}) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-border bg-surface">
      <table className="w-full min-w-[44rem] border-collapse text-sm">
        <thead>
          <tr className="border-b border-border text-left text-xs uppercase tracking-[0.12em] text-muted-foreground">
            <th className="px-4 py-3 font-medium">#</th>
            <th className="px-4 py-3 font-medium">Joueur</th>
            <th className="px-4 py-3 text-right font-medium">Checks</th>
            <th className="px-4 py-3 text-right font-medium">Envoyés</th>
            <th className="px-4 py-3 text-right font-medium">Reçus</th>
            <th className="px-4 py-3 text-right font-medium">Gardés</th>
            <th className="px-4 py-3 text-right font-medium">Temps</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr className="border-b border-border/60 last:border-b-0" key={row.slotId}>
              <td className="px-4 py-3 font-heading font-bold text-muted-foreground">{row.rank ?? "-"}</td>
              <td className="px-4 py-3">
                <div className="flex items-start gap-2.5">
                  <span
                    aria-hidden="true"
                    className="mt-1.5 size-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: colorBySlotName.get(row.slotName) ?? "var(--chart-series-1)" }}
                  />
                  <div className="grid gap-1">
                    <span className="font-semibold text-foreground">{row.label}</span>
                    <span className="text-xs text-muted-foreground">{row.game}</span>
                    {row.badges.length > 0 ? (
                      <span className="mt-0.5 flex flex-wrap gap-1">
                        {row.badges.map((badge) => (
                          <span
                            className="rounded-full border border-accent/40 bg-accent/10 px-2 py-0.5 text-[0.7rem] text-accent-text"
                            key={badge.key}
                            title={[SUPERLATIVE_HINTS[badge.key], formatSuperlativeValue(badge.value)]
                              .filter(Boolean)
                              .join(" - ")}
                          >
                            {badge.label}
                          </span>
                        ))}
                      </span>
                    ) : null}
                  </div>
                </div>
              </td>
              <td className="px-4 py-3 text-right tabular-nums text-foreground">{row.checksDone}</td>
              <td className="px-4 py-3 text-right tabular-nums text-foreground">{row.sentToOthers}</td>
              <td className="px-4 py-3 text-right tabular-nums text-foreground">{row.receivedFromOthers}</td>
              <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">{row.kept}</td>
              <td className="px-4 py-3 text-right text-muted-foreground">
                {row.completionSeconds !== null
                  ? formatDuration(row.completionSeconds)
                  : row.isInvalidated
                    ? "Invalidé"
                    : row.wasReleased
                      ? "Libéré"
                      : "Non terminé"}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
