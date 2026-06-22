"use client";

import { useState } from "react";
import { Check, Copy, Eye, EyeOff, Server } from "lucide-react";

function CopyField({ label, value }: { label: string; value: string }) {
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
        <p className="truncate font-mono text-sm text-foreground">{value}</p>
      </div>
      <button
        aria-label={`Copier ${label.toLowerCase()}`}
        className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:text-foreground"
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
  // Streamer mode: connection details are hidden by default so they can't be
  // accidentally shown on stream, and revealed on demand. Not persisted - each
  // load starts hidden, which is the safe default for streamers.
  const [revealed, setRevealed] = useState(false);

  return (
    <div className="min-w-0 rounded-lg border border-[color:var(--color-success)]/30 bg-[color:var(--color-success)]/5 p-4">
      <div className="mb-3 flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Server aria-hidden className="size-4 text-[color:var(--color-success)]" />
          <h3 className="text-sm font-semibold text-foreground">Infos de connexion</h3>
        </div>
        {revealed && (
          <button
            className="inline-flex shrink-0 items-center gap-1 rounded p-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
            onClick={() => setRevealed(false)}
            type="button"
          >
            <EyeOff aria-hidden className="size-3.5" />
            Masquer
          </button>
        )}
      </div>

      {revealed ? (
        <div className="grid grid-cols-1 gap-2">
          <CopyField label="Hôte" value={host} />
          <CopyField label="Port" value={String(port)} />
          <CopyField label="Mot de passe" value={password} />
          {adminPassword != null && (
            <CopyField label="Mot de passe admin" value={adminPassword} />
          )}
        </div>
      ) : (
        <div className="grid gap-3">
          <p className="text-xs text-muted-foreground">
            Masquées pour éviter de les exposer en stream. Affiche-les pour te connecter.
          </p>
          <button
            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            onClick={() => setRevealed(true)}
            type="button"
          >
            <Eye aria-hidden className="size-4" />
            Afficher les options de connexion
          </button>
        </div>
      )}
    </div>
  );
}
