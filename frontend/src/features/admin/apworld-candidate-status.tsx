"use client";

import { useState } from "react";
import { CheckCircle2, FlaskConical, RotateCcw, ShieldAlert, UploadCloud, XCircle } from "lucide-react";

import { ConfirmDialog } from "@/components/ui/confirm-dialog";

import type { ApworldCandidate } from "./admin-games-api";

type Props = {
  candidate: ApworldCandidate | null;
  /** An action is running: the buttons wait for it. */
  busy: boolean;
  onForce: () => void;
  onRetry: () => void;
  /** Story 38.14: put online a candidate awaiting approval. */
  onApprove: () => void;
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
 * A new apworld version waiting for its test, or refused by it (story 38.6), or tested and waiting for the admin
 * to put it online (story 38.14). The game keeps serving its current apworld meanwhile: this block says so, and
 * lets an admin overrule the test or validate the version.
 */
export function ApworldCandidateStatus({ candidate, busy, onForce, onRetry, onApprove }: Props) {
  const [confirmingForce, setConfirmingForce] = useState(false);
  const [confirmingApprove, setConfirmingApprove] = useState(false);

  if (candidate === null) return null;

  const version = candidate.versionTag ?? candidate.apworldHash.slice(0, SHORT_HASH_LENGTH);
  const origin = candidate.origin === "auto" ? "Mise à jour automatique" : "Import manuel";
  const rejected = candidate.status === "rejected";
  const expired = candidate.status === "expired";
  const awaiting = candidate.status === "awaiting";
  const decided = rejected || expired;
  const title = rejected
    ? "Nouvelle version rejetée"
    : expired
      ? "Test sans verdict"
      : awaiting
        ? "Testée, en attente de ta validation"
        : "Nouvelle version en test";

  return (
    <div
      className={`mt-4 grid gap-3 rounded border p-4 text-sm ${rejected ? "border-danger/40 bg-danger/10" : "border-accent/40 bg-accent/5"}`}
      role="status"
    >
      <p className="flex flex-wrap items-center gap-2 font-semibold text-foreground">
        {rejected ? (
          <XCircle aria-hidden className="size-4 text-danger" />
        ) : awaiting ? (
          <CheckCircle2 aria-hidden className="size-4 text-success" />
        ) : (
          <FlaskConical aria-hidden className="size-4 text-accent" />
        )}
        {title}
        <span className="font-mono font-normal">{version}</span>
      </p>
      <p className="text-muted-foreground">
        {origin}, soumise le {formatDate(candidate.submittedAt)}
        {rejected
          ? `, rejetée le ${formatDate(candidate.decidedAt)}.`
          : expired
            ? `, test expiré le ${formatDate(candidate.decidedAt)}.`
            : awaiting
              ? `, test réussi le ${formatDate(candidate.decidedAt)}.`
              : "."}{" "}
        En attendant, le jeu sert toujours sa version actuelle.
        {candidate.heldForApproval === true && candidate.status === "testing" ? " Une fois le test réussi, tu la mettras en ligne toi-même." : ""}
      </p>
      {decided && candidate.rejectionReason !== null && (
        <p className="rounded border border-border bg-background px-3 py-2 font-mono text-xs text-foreground">{candidate.rejectionReason}</p>
      )}

      <div className="flex flex-wrap gap-2">
        {awaiting && (
          <button
            aria-haspopup="dialog"
            className="inline-flex min-h-9 items-center gap-1.5 rounded bg-accent px-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
            disabled={busy}
            onClick={() => setConfirmingApprove(true)}
            type="button"
          >
            <UploadCloud aria-hidden className="size-4" />
            Mettre en ligne
          </button>
        )}
        {decided && (
          <button className={BUTTON} disabled={busy} onClick={onRetry} type="button">
            <RotateCcw aria-hidden className="size-4" />
            Relancer le test
          </button>
        )}
        {!awaiting && (
          <button aria-haspopup="dialog" className={BUTTON} disabled={busy} onClick={() => setConfirmingForce(true)} type="button">
            <ShieldAlert aria-hidden className="size-4" />
            {decided ? "Forcer quand même" : "Forcer"}
          </button>
        )}
      </div>

      <ConfirmDialog
        confirmLabel="Mettre en ligne"
        description="Les joueurs l'auront dès maintenant, et les parties privées passeront à cette version. Le salon staff sera prévenu que tu l'as validée."
        onConfirm={() => {
          setConfirmingApprove(false);
          onApprove();
        }}
        onOpenChange={setConfirmingApprove}
        open={confirmingApprove}
        pending={busy}
        title={`Mettre ${version} en ligne ?`}
      />

      {/* Story 38.13: a modal, not a confirmation unfolded inside the block. Nothing to force once the test passed. */}
      {!awaiting && (
        <ConfirmDialog
          confirmLabel="Forcer la mise en service"
          description="Les joueurs l'auront dès maintenant. Le salon staff sera prévenu que la version a été forcée."
          onConfirm={() => {
            setConfirmingForce(false);
            onForce();
          }}
          onOpenChange={setConfirmingForce}
          open={confirmingForce}
          pending={busy}
          title={`Mettre ${version} en service sans attendre ${decided ? "un test qui passe" : "le verdict du test"} ?`}
          tone="danger"
        />
      )}
    </div>
  );
}
