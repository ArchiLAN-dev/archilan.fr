"use client";

import { useState } from "react";
import { Check, Loader2 } from "lucide-react";

import { setRunReady } from "./personal-runs-api";
import type { PersonalRunParticipant } from "./types";

// Story 16.23: a player of a draft run says they are ready. Advisory only: the owner launches whenever they want.

type Readiness = { ready: number; total: number };

/** « 3 / 4 prêts », then « Tout le monde est prêt »; null when nobody can be ready yet. */
export function readinessLabel(readiness: Readiness | null | undefined): string | null {
  if (readiness === null || readiness === undefined || readiness.total === 0) return null;
  if (readiness.ready >= readiness.total) return "Tout le monde est prêt";
  return `${readiness.ready} / ${readiness.total} prêt${readiness.ready > 1 ? "s" : ""}`;
}

/** The badge next to a ready player. A player who is not ready gets none, never a « pas prêt ». */
export function ReadyBadge({ participant }: { participant: PersonalRunParticipant }) {
  if (participant.ready !== true) return null;

  return (
    <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-[color:var(--color-success)]/40 bg-[color:var(--color-success)]/10 px-2 py-0.5 text-xs font-medium text-[color:var(--color-success)]">
      <Check aria-hidden className="size-3" />
      Prêt
    </span>
  );
}

export function ReadinessSummary({ readiness, className = "" }: { readiness: Readiness | null | undefined; className?: string }) {
  const label = readinessLabel(readiness);
  if (label === null || readiness === null || readiness === undefined) return null;
  const complete = readiness.ready >= readiness.total;

  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium ${
        complete
          ? "border-[color:var(--color-success)]/40 bg-[color:var(--color-success)]/10 text-[color:var(--color-success)]"
          : "border-border text-muted-foreground"
      } ${className}`}
    >
      {complete ? <Check aria-hidden className="size-3" /> : null}
      {label}
    </span>
  );
}

type ToggleProps = {
  runId: string;
  participant: PersonalRunParticipant;
  onChanged: () => void;
};

/** The player's own switch. Hidden for someone with neither a game nor a co-played slot. */
export function ReadyToggle({ runId, participant, onChanged }: ToggleProps) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (participant.canBeReady !== true) return null;
  const ready = participant.ready === true;

  async function toggle() {
    setBusy(true);
    setError(null);
    const message = await setRunReady(runId, !ready);
    setBusy(false);
    if (message !== null) {
      setError(message);
      return;
    }
    onChanged();
  }

  return (
    <div className="mt-3 flex flex-wrap items-center gap-2">
      <button
        aria-pressed={ready}
        className={`inline-flex items-center gap-1.5 rounded border px-3 py-1.5 text-xs font-semibold transition-colors disabled:opacity-50 ${
          ready
            ? "border-[color:var(--color-success)]/40 bg-[color:var(--color-success)]/10 text-[color:var(--color-success)] hover:bg-[color:var(--color-success)]/15"
            : "border-border text-foreground hover:border-accent hover:text-accent-text"
        }`}
        disabled={busy}
        onClick={() => void toggle()}
        title={ready ? "Clique pour ne plus être prêt" : undefined}
        type="button"
      >
        {busy ? <Loader2 aria-hidden className="size-3.5 animate-spin" /> : <Check aria-hidden className="size-3.5" />}
        {ready ? "Prêt" : "Je suis prêt"}
      </button>
      <span className="text-xs text-muted-foreground">
        {ready
          ? "Changer tes jeux ou ta config annule ce statut."
          : "Indique au créateur de la partie que tu as fini de choisir et de configurer."}
      </span>
      {error !== null ? <span className="w-full text-xs text-[color:var(--color-danger)]">{error}</span> : null}
    </div>
  );
}
