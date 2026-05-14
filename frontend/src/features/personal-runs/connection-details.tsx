"use client";

import { useState } from "react";
import { Check, Copy, Server } from "lucide-react";

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
    <div className="flex items-center justify-between gap-3 rounded border border-border bg-background px-3 py-2">
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
}: {
  host: string;
  port: number;
  password: string;
}) {
  return (
    <div className="rounded-lg border border-[color:var(--color-success)]/30 bg-[color:var(--color-success)]/5 p-4">
      <div className="mb-3 flex items-center gap-2">
        <Server aria-hidden className="size-4 text-[color:var(--color-success)]" />
        <h3 className="text-sm font-semibold text-foreground">Infos de connexion</h3>
      </div>
      <div className="grid gap-2">
        <CopyField label="Hôte" value={host} />
        <CopyField label="Port" value={String(port)} />
        <CopyField label="Mot de passe" value={password} />
      </div>
    </div>
  );
}
