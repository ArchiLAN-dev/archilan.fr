"use client";

import { useState } from "react";
import { Check, Copy, Link, RefreshCw } from "lucide-react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export function InviteLinkPanel({
  runId,
  inviteToken,
  onTokenRegenerated,
}: {
  runId: string;
  inviteToken: string;
  onTokenRegenerated: (newToken: string) => void;
}) {
  const [copied, setCopied] = useState(false);
  const [regenerating, setRegenerating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const inviteUrl = `${env.appUrl}/runs/join/${inviteToken}`;
  const maskedUrl = `${env.appUrl}/runs/join/${inviteToken.slice(0, 8)}…`;

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(inviteUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      /* clipboard unavailable */
    }
  }

  async function handleRegenerate() {
    setRegenerating(true);
    setError(null);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/invite/regenerate`, {
        method: "POST",
      });
      if (!res.ok) {
        setError("Impossible de régénérer le lien.");
        return;
      }
      const payload = (await res.json()) as { data: { inviteToken: string } };
      onTokenRegenerated(payload.data.inviteToken);
    } catch {
      setError("Erreur réseau.");
    } finally {
      setRegenerating(false);
    }
  }

  return (
    <div className="rounded-lg border border-border bg-surface p-4">
      <div className="mb-3 flex items-center gap-2">
        <Link aria-hidden className="size-4 text-accent-text" />
        <h3 className="text-sm font-semibold text-foreground">Lien d&apos;invitation</h3>
      </div>
      <div className="flex items-center gap-2">
        <span className="min-w-0 flex-1 truncate rounded border border-border bg-background px-3 py-2 font-mono text-sm text-muted-foreground">
          {maskedUrl}
        </span>
        <button
          aria-label="Copier le lien d'invitation"
          className="shrink-0 rounded border border-border px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          onClick={() => void handleCopy()}
          type="button"
        >
          {copied ? (
            <Check aria-hidden className="size-4 text-[color:var(--color-success)]" />
          ) : (
            <Copy aria-hidden className="size-4" />
          )}
        </button>
        <button
          aria-label="Régénérer le lien"
          className="shrink-0 rounded border border-border px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-50"
          disabled={regenerating}
          onClick={() => void handleRegenerate()}
          title="Régénérer le lien d'invitation (l'ancien sera invalidé)"
          type="button"
        >
          <RefreshCw
            aria-hidden
            className={["size-4", regenerating ? "animate-spin" : ""].join(" ")}
          />
        </button>
      </div>
      {error && <p className="mt-2 text-xs text-[color:var(--color-danger)]">{error}</p>}
    </div>
  );
}
