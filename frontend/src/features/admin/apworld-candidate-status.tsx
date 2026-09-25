"use client";

import { useState } from "react";
import { FlaskConical, RotateCcw, ShieldAlert, XCircle } from "lucide-react";

import type { ApworldCandidate } from "./admin-games-api";

type Props = {
  candidate: ApworldCandidate | null;
  /** An action is running: the buttons wait for it. */
  busy: boolean;
  onForce: () => void;
  onRetry: () => void;
};

const SHORT_HASH_LENGTH = 16;

const DATE_FORMAT = new Intl.DateTimeFormat("fr-FR", { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Paris" });

function formatDate(iso: string | null): string {
  if (iso === null) return "";
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? "" : DATE_FORMAT.format(date);
}

const BUTTON =
  "inline-flex min-h-9 items-center gap-1.5 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50";

/**
 * A new apworld version waiting for its test, or refused by it (story 38.6). The game keeps serving its
 * current apworld meanwhile: this block says so, and lets an admin overrule the test.
 */
export function ApworldCandidateStatus({ candidate, busy, onForce, onRetry }: Props) {
  const [confirmingForce, setConfirmingForce] = useState(false);

  if (candidate === null) return null;

  const version = candidate.versionTag ?? candidate.apworldHash.slice(0, SHORT_HASH_LENGTH);
  const origin = candidate.origin === "auto" ? "Mise à jour automatique" : "Import manuel";
  const rejected = candidate.status === "rejected";

  return (
    <div
      className={`mt-4 grid gap-3 rounded border p-4 text-sm ${rejected ? "border-danger/40 bg-danger/10" : "border-accent/40 bg-accent/5"}`}
      role="status"
    >
      <p className="flex flex-wrap items-center gap-2 font-semibold text-foreground">
        {rejected ? <XCircle aria-hidden className="size-4 text-danger" /> : <FlaskConical aria-hidden className="size-4 text-accent" />}
        {rejected ? "Nouvelle version rejetée" : "Nouvelle version en test"}
        <span className="font-mono font-normal">{version}</span>
      </p>
      <p className="text-muted-foreground">
        {origin}, soumise le {formatDate(candidate.submittedAt)}
        {rejected ? `, rejetée le ${formatDate(candidate.decidedAt)}.` : "."} En attendant, le jeu sert toujours sa version actuelle.
      </p>
      {rejected && candidate.rejectionReason !== null && (
        <p className="rounded border border-border bg-background px-3 py-2 font-mono text-xs text-foreground">{candidate.rejectionReason}</p>
      )}

      <div className="flex flex-wrap gap-2">
        {rejected && (
          <button className={BUTTON} disabled={busy} onClick={onRetry} type="button">
            <RotateCcw aria-hidden className="size-4" />
            Relancer le test
          </button>
        )}
        <button aria-haspopup="dialog" className={BUTTON} disabled={busy} onClick={() => setConfirmingForce(true)} type="button">
          <ShieldAlert aria-hidden className="size-4" />
          {rejected ? "Forcer quand même" : "Forcer"}
        </button>
      </div>

      {confirmingForce && (
        <div aria-modal="true" className="grid gap-3 rounded border border-border bg-background p-3" role="dialog">
          <p className="text-foreground">
            Mettre {version} en service sans attendre {rejected ? "un test qui passe" : "le verdict du test"} ? Les joueurs l&apos;auront
            dès maintenant. Le salon staff sera prévenu que la version a été forcée.
          </p>
          <div className="flex gap-2">
            <button
              className={BUTTON}
              disabled={busy}
              onClick={() => {
                setConfirmingForce(false);
                onForce();
              }}
              type="button"
            >
              Forcer la mise en service
            </button>
            <button className={BUTTON} onClick={() => setConfirmingForce(false)} type="button">
              Annuler
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
