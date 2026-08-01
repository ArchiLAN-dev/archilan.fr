"use client";

import { useState } from "react";
import { Check, Copy, Eye, EyeOff } from "lucide-react";

// Fixed-length mask: does not leak the real value's length.
const MASK = "••••••••";

// Streamer-safe connection field: masked by default on every mount, per field -
// copying works while masked (the handler reads the prop, the value never enters
// the DOM until revealed). Shared by the private-run, event-session and weekly-run
// connection panels (stories 17.21 / 17.22).
export function SecretField({ label, value }: { label: string; value: string }) {
  const [revealed, setRevealed] = useState(false);
  const [copied, setCopied] = useState(false);

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      /* clipboard unavailable */
    }
  }

  return (
    <div className="flex min-w-0 items-center justify-between gap-3 rounded border border-border bg-background px-3 py-2">
      <div className="min-w-0">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className="truncate font-mono text-sm text-foreground">{revealed ? value : MASK}</p>
      </div>
      <div className="flex shrink-0 items-center gap-1">
        <button
          aria-label={`${revealed ? "Masquer" : "Afficher"} ${label.toLowerCase()}`}
          className="rounded p-1 text-muted-foreground transition-colors hover:text-foreground"
          onClick={() => setRevealed((v) => !v)}
          type="button"
        >
          {revealed ? <EyeOff aria-hidden className="size-4" /> : <Eye aria-hidden className="size-4" />}
        </button>
        <button
          aria-label={`Copier ${label.toLowerCase()}`}
          className="rounded p-1 text-muted-foreground transition-colors hover:text-foreground"
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
    </div>
  );
}
