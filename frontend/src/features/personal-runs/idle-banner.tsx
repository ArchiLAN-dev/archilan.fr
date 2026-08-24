"use client";

import { useEffect, useState } from "react";
import { AlertTriangle, Loader2, PauseCircle, Play, RotateCcw } from "lucide-react";

import { formatIdleDuration } from "./idle-duration";

/**
 * The state of a sleeping run, and the one gesture that gets it going again (story 16.15).
 *
 * Rendered above the tab bar rather than inside "Vue d'ensemble": a run in `idle` has exactly one
 * useful action, and which tab the reader happens to be on should not hide it.
 *
 * Two variants that must never look alike. With a save, resuming reloads it and costs nothing, so
 * it stays a single click. Without one, relaunching restarts the multiworld from zero and wipes
 * every participant's progress - it gets its own register and a confirmation. Note that the design
 * tokens `--color-warning` and `--color-accent-warm` hold the same value, so the warning register
 * alone would not tell the two apart; the destructive path leans on `--color-danger` for its button
 * and its dialog, which is also what the rest of the page uses for irreversible actions.
 */
export function IdleBanner({
  pausedWithoutSave,
  lastActivityAt,
  busy,
  onResume,
}: {
  pausedWithoutSave: boolean;
  lastActivityAt: string | null;
  busy: boolean;
  onResume: () => void;
}) {
  const [now, setNow] = useState<number | null>(null);
  const [confirming, setConfirming] = useState(false);

  useEffect(() => {
    const tick = () => { setNow(Date.now()); };
    tick();
    const interval = setInterval(tick, 60_000);
    return () => { clearInterval(interval); };
  }, []);

  // Null until the first client tick, so the server-rendered markup carries no clock-dependent text.
  const duration = lastActivityAt !== null && now !== null ? formatIdleDuration(lastActivityAt, now) : null;

  return (
    <>
      {confirming && (
        <RestartConfirmDialog
          busy={busy}
          onCancel={() => { setConfirming(false); }}
          onConfirm={() => { setConfirming(false); onResume(); }}
        />
      )}

      <section
        className={`rounded-lg border p-4 sm:p-5 ${
          pausedWithoutSave
            ? "border-[color:var(--color-danger)]/40 bg-[color:var(--color-danger)]/5"
            : "border-[color:var(--color-accent-warm)]/40 bg-[color:var(--color-accent-warm)]/5"
        }`}
      >
        <div className="flex items-start gap-3">
          {pausedWithoutSave ? (
            <AlertTriangle aria-hidden className="mt-0.5 size-5 shrink-0 text-[color:var(--color-danger)]" />
          ) : (
            <PauseCircle aria-hidden className="mt-0.5 size-5 shrink-0 text-[color:var(--color-accent-warm)]" />
          )}

          <div className="min-w-0 flex-1">
            <h2 className="font-heading text-base font-semibold text-foreground">
              {pausedWithoutSave
                ? "Aucune sauvegarde disponible"
                : `Partie en veille${duration !== null ? ` depuis ${duration}` : ""}`}
            </h2>

            <p className="mt-1 text-sm text-foreground/80">
              {pausedWithoutSave
                ? "Le serveur s'est arrêté sans sauvegarde exploitable. La relancer repart de zéro : la progression de tous les participants sera perdue. La configuration et les slots, eux, sont conservés."
                : "Le serveur s'est arrêté faute d'activité. En la reprenant, la dernière sauvegarde est rechargée automatiquement."}
            </p>

            {pausedWithoutSave && duration !== null && (
              <p className="mt-1 text-xs text-muted-foreground">En veille depuis {duration}.</p>
            )}

            {pausedWithoutSave ? (
              <button
                aria-haspopup="dialog"
                className="mt-4 inline-flex items-center gap-2 rounded border border-[color:var(--color-danger)]/50 bg-[color:var(--color-danger)]/10 px-4 py-2 text-sm font-semibold text-[color:var(--color-danger)] transition-colors hover:bg-[color:var(--color-danger)]/20 disabled:opacity-50"
                disabled={busy}
                onClick={() => { setConfirming(true); }}
                type="button"
              >
                {busy ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <RotateCcw aria-hidden className="size-4" />}
                Relancer depuis le début
              </button>
            ) : (
              <button
                className="mt-4 inline-flex items-center gap-2 rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
                disabled={busy}
                onClick={onResume}
                type="button"
              >
                {busy ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Play aria-hidden className="size-4" />}
                Reprendre
              </button>
            )}
          </div>
        </div>
      </section>
    </>
  );
}

/**
 * Guards the one path that destroys something. The safe variant deliberately has no dialog: asking
 * to confirm a harmless click teaches the reflex of confirming without reading, which is exactly
 * what this one needs them not to have.
 */
function RestartConfirmDialog({
  busy,
  onConfirm,
  onCancel,
}: {
  busy: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div aria-modal="true" className="w-full max-w-sm rounded-lg border border-border bg-surface p-6 shadow-xl" role="dialog">
        <div className="mb-4 flex items-start gap-3">
          <AlertTriangle aria-hidden className="mt-0.5 size-5 shrink-0 text-[color:var(--color-danger)]" />
          <div>
            <h2 className="font-heading font-semibold text-foreground">Relancer depuis le début ?</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              La partie repart de zéro et la progression de tous les participants est perdue. La configuration et les
              slots sont conservés. Cette action est irréversible.
            </p>
          </div>
        </div>
        <div className="flex justify-end gap-3">
          <button
            className="rounded border border-border px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground"
            onClick={onCancel}
            type="button"
          >
            Annuler
          </button>
          <button
            className="inline-flex items-center gap-2 rounded bg-[color:var(--color-danger)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50"
            disabled={busy}
            onClick={onConfirm}
            type="button"
          >
            {busy && <Loader2 aria-hidden className="size-4 animate-spin" />}
            Relancer
          </button>
        </div>
      </div>
    </div>
  );
}
