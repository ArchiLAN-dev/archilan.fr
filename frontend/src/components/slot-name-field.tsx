"use client";

import { useState } from "react";
import { Check, Copy } from "lucide-react";

/** A slot the player connects with: its Archipelago name, and its game when they play several. */
export type ConnectionSlot = { name: string; game: string | null };

/**
 * The slot name, first thing a client asks for (story 17.29). Shown in clear and large, unlike the
 * address and password: it is no secret - the game and the tracker print it anyway - and it is the one
 * value a player must type exactly.
 */
export function SlotNameField({ slot, showGame }: { slot: ConnectionSlot; showGame: boolean }) {
  const [copied, setCopied] = useState(false);

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(slot.name);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      /* clipboard unavailable */
    }
  }

  const label = showGame && slot.game !== null ? `Nom du slot - ${slot.game}` : "Nom du slot";

  return (
    <div className="flex min-w-0 items-center justify-between gap-3 rounded border border-accent/50 bg-accent/10 px-3 py-2.5">
      <div className="min-w-0">
        <p className="truncate text-xs font-semibold uppercase tracking-wide text-accent-text">{label}</p>
        <p className="truncate font-mono text-lg font-bold text-foreground">{slot.name}</p>
      </div>
      <button
        aria-label={`Copier le nom du slot ${slot.name}`}
        className="shrink-0 rounded p-1.5 text-muted-foreground transition-colors hover:text-foreground"
        onClick={() => void handleCopy()}
        type="button"
      >
        {copied ? (
          <Check aria-hidden className="size-4 text-[color:var(--color-success)]" />
        ) : (
          <Copy aria-hidden className="size-4" />
        )}
      </button>
    </div>
  );
}
