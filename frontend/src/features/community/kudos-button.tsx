"use client";

import { useState } from "react";
import { ThumbsUp } from "lucide-react";

import { toggleKudos } from "./community-kudos-api";

/**
 * A toggle "kudos" button (count + given state). Controlled by its own optimistic state, reconciled with
 * the server's authoritative {given,count} on each toggle.
 */
export function KudosButton({
  targetType,
  targetId,
  initialCount,
  initialGiven,
}: {
  targetType: string;
  targetId: string;
  initialCount: number;
  initialGiven: boolean;
}) {
  const [count, setCount] = useState(initialCount);
  const [given, setGiven] = useState(initialGiven);
  const [busy, setBusy] = useState(false);

  async function handleToggle() {
    if (busy) return;
    setBusy(true);
    const result = await toggleKudos(targetType, targetId);
    setBusy(false);
    if (result) {
      setGiven(result.given);
      setCount(result.count);
    }
  }

  return (
    <button
      aria-label={given ? "Retirer mon kudos" : "Donner un kudos"}
      aria-pressed={given}
      className={`inline-flex min-h-7 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 text-xs font-semibold transition-colors disabled:opacity-50 ${
        given
          ? "border-accent bg-accent/15 text-accent-text"
          : "border-border text-muted-foreground hover:border-accent hover:text-foreground"
      }`}
      disabled={busy}
      onClick={() => { void handleToggle(); }}
      type="button"
    >
      <ThumbsUp aria-hidden className="size-3.5" />
      {count > 0 ? count : null}
    </button>
  );
}
