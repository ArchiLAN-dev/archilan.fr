"use client";

import { useState } from "react";
import { Loader2, ShieldCheck } from "lucide-react";

import { setRunCoOwner } from "./personal-runs-api";
import type { PersonalRunParticipant } from "./types";

// Story 16.27: the owner of a private run lets participants pilot it (launch, stop, configuration) when they are away.

/** The badge of a co-organiser, and of nobody else. */
export function CoOwnerBadge({ participant }: { participant: PersonalRunParticipant }) {
  if (participant.coOwner !== true) return null;
  return (
    <span
      className="inline-flex items-center gap-1 rounded-full border border-accent/40 bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent-text"
      title="Peut lancer, arrêter et régler la partie"
    >
      <ShieldCheck aria-hidden className="size-3" />
      Co-organisateur
    </span>
  );
}

/** The owner's switch on one participant's row. */
export function CoOwnerToggle({
  runId,
  participant,
  onChanged,
}: {
  runId: string;
  participant: PersonalRunParticipant;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const coOwner = participant.coOwner === true;

  async function toggle() {
    setBusy(true);
    setError(null);
    const message = await setRunCoOwner(runId, participant.userId, !coOwner);
    setBusy(false);
    if (message !== null) {
      setError(message);
      return;
    }
    onChanged();
  }

  return (
    <span className="inline-flex flex-col items-end gap-1">
      <button
        aria-pressed={coOwner}
        className="inline-flex shrink-0 items-center gap-1.5 rounded border border-border px-2.5 py-1 text-xs font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text disabled:opacity-50"
        disabled={busy}
        onClick={() => void toggle()}
        title={coOwner
          ? "Lui retirer le droit de lancer, arrêter et régler la partie"
          : "Lui donner le droit de lancer, arrêter et régler la partie. Le spoiler et les participants restent à toi."}
        type="button"
      >
        {busy ? <Loader2 aria-hidden className="size-3.5 animate-spin" /> : <ShieldCheck aria-hidden className="size-3.5" />}
        {coOwner ? "Retirer les droits" : "Co-organisateur"}
      </button>
      {error !== null ? <span className="text-xs text-[color:var(--color-danger)]">{error}</span> : null}
    </span>
  );
}
