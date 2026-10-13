import Link from "next/link";
import { Swords } from "lucide-react";

import type { EventRecapIndexEntry } from "@/features/recap/recap-api";
import { formatDuration } from "@/features/recap/recap-format";

// Per-event recap index (story 32.3): one row per finished session that has a public recap.
// Hidden entirely when empty - most events have no recap yet (pre-32.1 sessions never will).
export function EventPartiesSection({ parties }: { parties: EventRecapIndexEntry[] }) {
  if (parties.length === 0) {
    return null;
  }

  return (
    <section aria-labelledby="event-parties-heading" className="rounded-lg border border-border p-6">
      <div className="flex items-center gap-3">
        <Swords aria-hidden="true" className="size-5 text-accent-text" />
        <h2 className="font-heading text-2xl font-semibold text-foreground" id="event-parties-heading">
          Les parties de cet événement
        </h2>
      </div>
      <ul className="mt-6 grid gap-3">
        {parties.map((party) => (
          <li key={party.sessionId}>
            <Link
              className="flex flex-col gap-2 rounded border border-border bg-surface p-4 transition-colors hover:border-accent sm:flex-row sm:items-center sm:justify-between"
              href={`/parties/${party.sessionId}`}
            >
              <span className="flex flex-col gap-1">
                <span className="font-semibold text-foreground">
                  {partyLabel(party.finishedAt ?? party.startedAt)}
                </span>
                <span className="text-sm text-muted-foreground">
                  {party.winner
                    ? `Vainqueur : ${party.winner.playerName} (${party.winner.game})`
                    : "Objectifs non atteints"}
                </span>
              </span>
              <span className="flex items-center gap-4 text-sm text-muted-foreground">
                <span>{party.playerCount} joueurs</span>
                {party.durationSeconds !== null ? <span>{formatDuration(party.durationSeconds)}</span> : null}
                <span className="font-semibold text-accent-text">Voir le récap</span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}

function partyLabel(iso: string | null): string {
  const date = iso !== null ? new Date(iso) : null;
  if (date === null || Number.isNaN(date.getTime())) {
    return "Partie de l'événement";
  }
  return `Partie du ${date.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" })}`;
}
