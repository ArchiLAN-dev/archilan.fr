"use client";

import { useState } from "react";
import { Check, Copy, Eye, EyeOff, Server } from "lucide-react";

/**
 * One connection field, masked individually (story 16.13): the value renders as dots until ITS own
 * eye is toggled, and the copy button always copies the real value - connecting while streaming
 * never requires showing anything on screen. Not persisted: every load starts masked, the safe
 * default for streamers.
 */
function SecretField({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);
  const [revealed, setRevealed] = useState(false);

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
        <p aria-live="polite" className="truncate font-mono text-sm text-foreground">
          {revealed ? value : "••••••••"}
        </p>
      </div>
      <div className="flex shrink-0 items-center gap-1">
        <button
          aria-label={revealed ? `Masquer ${label.toLowerCase()}` : `Afficher ${label.toLowerCase()}`}
          aria-pressed={revealed}
          className="rounded p-1 text-muted-foreground transition-colors hover:text-foreground"
          onClick={() => setRevealed((prev) => !prev)}
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
        Valeurs masquées par défaut pour le stream - la copie fonctionne sans les afficher.
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
