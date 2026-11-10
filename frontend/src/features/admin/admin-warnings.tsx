"use client";

import { AlertTriangle } from "lucide-react";
import { useId, useState } from "react";

import type { AdminActionResult, AdminWarning } from "./admin-registration-api";

/**
 * Story 5.10: runs an admin action that may cross member-side rules. The first call goes without acknowledgement; if the
 * API answers with warnings, they are kept for the dialog, and `confirm` replays the action acknowledged.
 */
export function useAcknowledgedAction<T>(
  action: (acknowledgeWarnings: boolean) => Promise<AdminActionResult<T>>,
  onDone: (data: T) => void,
) {
  const [warnings, setWarnings] = useState<AdminWarning[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run(acknowledgeWarnings: boolean) {
    setBusy(true);
    setError(null);
    const result = await action(acknowledgeWarnings);
    setBusy(false);
    if (result.kind === "warnings") {
      setWarnings(result.warnings);
      return;
    }
    setWarnings(null);
    if (result.kind === "error") {
      setError(result.message);
      return;
    }
    onDone(result.data);
  }

  return {
    busy,
    error,
    warnings,
    run: () => { void run(false); },
    confirm: () => { void run(true); },
    dismiss: () => { setWarnings(null); },
  };
}

type DialogProps = {
  warnings: AdminWarning[];
  busy: boolean;
  confirmLabel: string;
  onConfirm: () => void;
  onCancel: () => void;
};

/** The rules the admin is about to cross, listed before they go ahead anyway. */
export function AdminWarningsDialog({ warnings, busy, confirmLabel, onConfirm, onCancel }: DialogProps) {
  const titleId = useId();

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div
        aria-labelledby={titleId}
        aria-modal="true"
        className="w-full max-w-md border border-border bg-surface p-6 shadow-xl"
        role="alertdialog"
      >
        <h2 className="flex items-center gap-2 font-heading text-xl font-semibold text-foreground" id={titleId}>
          <AlertTriangle aria-hidden="true" className="size-5 text-accent-warm" />
          Hors des règles d&apos;inscription
        </h2>
        <p className="mt-3 text-sm text-muted-foreground">
          Un joueur serait bloqué ici. En tant qu&apos;admin, tu peux passer outre :
        </p>
        <ul className="mt-3 grid gap-2 text-sm text-foreground">
          {warnings.map((warning) => (
            <li className="border-l-2 border-accent-warm pl-3" key={warning.code}>
              {warning.message}
            </li>
          ))}
        </ul>
        <div className="mt-6 flex flex-wrap justify-end gap-3">
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-border px-3 text-sm font-semibold text-foreground hover:border-accent"
            disabled={busy}
            onClick={onCancel}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-accent-warm bg-accent-warm px-3 text-sm font-semibold text-background hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
            disabled={busy}
            onClick={onConfirm}
            type="button"
          >
            {busy ? "..." : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
