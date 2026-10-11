"use client";

import { useState } from "react";
import { Check, Copy, Link, RefreshCw } from "lucide-react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { FaDiscord } from "react-icons/fa";
import { externalLinks } from "@/lib/external-links";

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

  // Story 43.20: a row of the « Faire venir des joueurs » card rather than a card of its own.
  // grid-cols-1 (minmax(0, 1fr)): the one-line URL must not widen the card on a phone.
  return (
    <div className="grid grid-cols-1 gap-2">
      {/* Story 43.22: a heading like the settings', with who the link is for beside it. */}
      <p className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-foreground">
          <Link aria-hidden className="size-3.5 text-muted-foreground" />
          Lien d&apos;invitation
        </span>
        <span className="text-xs text-muted-foreground">Pour quelqu&apos;un qui n&apos;est pas dans tes amis.</span>
      </p>
      <div className="flex items-center gap-2">
        <span className="min-w-0 flex-1 truncate rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm text-muted-foreground">
          {maskedUrl}
        </span>
        <button
          aria-label="Copier le lien d'invitation"
          className="inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium text-foreground transition-colors hover:border-accent"
          onClick={() => void handleCopy()}
          type="button"
        >
          {copied ? (
            <Check aria-hidden className="size-4 text-[color:var(--color-success)]" />
          ) : (
            <Copy aria-hidden className="size-4" />
          )}
          <span className="hidden sm:inline">{copied ? "Copié" : "Copier"}</span>
        </button>
        <button
          aria-label="Régénérer le lien"
          className="inline-flex min-h-9 shrink-0 items-center rounded-lg border border-border px-3 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-50"
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
      {error && <p className="text-xs text-[color:var(--color-danger)]">{error}</p>}
      {/* Story 30.50: where players looking for a game are. */}
      <p className="text-xs text-muted-foreground">
        <a
          className="inline-flex items-center gap-1 font-semibold text-accent-text hover:text-accent-text-hover"
          href={externalLinks.archilanDiscord}
          rel="noopener noreferrer"
          target="_blank"
        >
          <FaDiscord aria-hidden className="size-3.5" />
          Partage-le sur le Discord
          <span className="sr-only"> (nouvel onglet)</span>
        </a>
      </p>
    </div>
  );
}
