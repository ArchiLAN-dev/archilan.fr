import Link from "next/link";
import { Gamepad2, Loader2, RotateCcw } from "lucide-react";
import type { PersonalRun } from "./types";
import { PersonalRunStatusBadge } from "./personal-run-status-badge";

function formatDate(iso: string) {
  return new Date(iso).toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

function formatIdleTime(iso: string): string {
  const diffMs = Date.now() - new Date(iso).getTime();
  const totalMin = Math.floor(diffMs / 60_000);
  const hours = Math.floor(totalMin / 60);
  const minutes = totalMin % 60;
  return hours > 0 ? `Inactif depuis ${hours}h ${minutes}min` : `Inactif depuis ${minutes}min`;
}

export function PersonalRunCard({
  run,
  restarting = false,
  onRestart,
}: {
  run: PersonalRun;
  restarting?: boolean;
  onRestart?: (run: PersonalRun) => void;
}) {
  const gameCount = run.gameSelectionConfig?.length ?? 0;
  const canRestart = run.status === "idle" && run.sessionId !== null && !run.pausedWithoutSave;

  return (
    <div className="rounded-lg border border-border bg-surface px-5 py-4 transition-colors hover:border-border/60">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <Link className="min-w-0 flex-1" href={`/runs/${run.id}`}>
          <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-heading font-semibold text-foreground">{run.title}</span>
              <PersonalRunStatusBadge status={run.status} />
            </div>
            <div className="flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
              <span>Créée le {formatDate(run.createdAt)}</span>
              {gameCount > 0 && (
                <span className="flex items-center gap-1.5">
                  <Gamepad2 aria-hidden className="size-3.5" />
                  {gameCount} {gameCount === 1 ? "jeu" : "jeux"}
                </span>
              )}
              {run.status === "idle" && run.lastActivityAt !== null && (
                <span className="text-xs text-[color:var(--color-accent-warm)]">
                  {formatIdleTime(run.lastActivityAt)}
                </span>
              )}
            </div>
          </div>
        </Link>
        <div className="flex shrink-0 items-center gap-3">
          {run.status === "idle" && onRestart ? (
            <button
              className="inline-flex min-h-9 items-center justify-center gap-2 rounded border border-[color:var(--color-accent-warm)]/50 bg-[color:var(--color-accent-warm)]/10 px-3 text-sm font-semibold text-[color:var(--color-accent-warm)] transition-colors hover:bg-[color:var(--color-accent-warm)]/20 disabled:cursor-not-allowed disabled:opacity-50"
              disabled={!canRestart || restarting}
              onClick={() => onRestart(run)}
              title={run.pausedWithoutSave ? "Reprise impossible : aucune sauvegarde disponible" : undefined}
              type="button"
            >
              {restarting ? (
                <Loader2 aria-hidden className="size-4 animate-spin" />
              ) : (
                <RotateCcw aria-hidden className="size-4" />
              )}
              Reprendre
            </button>
          ) : null}
          <Link className="text-sm text-muted-foreground hover:text-foreground" href={`/runs/${run.id}`}>
            →
          </Link>
        </div>
      </div>
    </div>
  );
}
