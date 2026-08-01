"use client";

import { useState } from "react";
import { Check, Copy, Eye, EyeOff, Server } from "lucide-react";

// Fixed-length mask: does not leak the real value's length.
const MASK = "••••••••";

function SecretField({ label, value }: { label: string; value: string }) {
  // Streamer mode: masked by default on every mount, per field - copying works
  // while masked (the handler reads the prop, the value never enters the DOM).
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

export function ConnectionDetails({
  host,
  port,
  password,
  adminPassword,
}: {
  host: string;
  port: number;
  password: string;
  adminPassword?: string | null;
}) {
  return (
    <div className="min-w-0 rounded-lg border border-[color:var(--color-success)]/30 bg-[color:var(--color-success)]/5 p-4">
      <div className="mb-1 flex items-center gap-2">
        <Server aria-hidden className="size-4 text-[color:var(--color-success)]" />
        <h3 className="text-sm font-semibold text-foreground">Infos de connexion</h3>
      </div>
      <p className="mb-3 text-xs text-muted-foreground">
        Valeurs masquées pour le stream - la copie fonctionne sans les afficher.
      </p>
      <div className="grid grid-cols-1 gap-2">
        <SecretField label="Hôte" value={host} />
        <SecretField label="Port" value={String(port)} />
        <SecretField label="Mot de passe" value={password} />
        {adminPassword != null && <SecretField label="Mot de passe admin" value={adminPassword} />}
      </div>
    </div>
  );
}
