"use client";

import { ChevronDown, ChevronRight, Download, Edit2, Infinity, PowerOff, Timer, Users } from "lucide-react";
import Link from "next/link";

import type { AdminCurrentWeeklyRun, AdminWeeklyTemplateListItem } from "./admin-weekly-runs-api";

export function formatTime(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  return `${h}h${String(m).padStart(2, "0")}`;
}

// ── Template card ─────────────────────────────────────────────────────────────

type TemplateCardProps = {
  tpl: AdminWeeklyTemplateListItem;
  deactivating: boolean;
  onDeactivate: () => void;
  // When set, the card body links to the template's run history page.
  runsHref?: string;
};

export function TemplateCard({ tpl, deactivating, onDeactivate, runsHref }: TemplateCardProps) {
  const body = (
    <>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="truncate font-semibold text-foreground">
            {tpl.name ?? <span className="italic text-muted-foreground">Sans nom</span>}
          </p>
          <p className="mt-0.5 truncate text-sm text-muted-foreground">{tpl.gameName}</p>
        </div>
        <span
          className={[
            "shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium",
            tpl.isActive
              ? "bg-emerald-500/15 text-emerald-400"
              : "bg-surface-2 text-muted-foreground",
          ].join(" ")}
        >
          {tpl.isActive ? "Actif" : "Inactif"}
        </span>
      </div>

      <div className="mt-4 flex items-center gap-4 text-xs text-muted-foreground">
        <span className="flex items-center gap-1">
          <Timer aria-hidden="true" className="size-3.5" />
          {tpl.maxAttempts != null ? (
            <>{tpl.maxAttempts} tentative{tpl.maxAttempts !== 1 ? "s" : ""}</>
          ) : (
            <span className="flex items-center gap-0.5">
              <Infinity aria-hidden="true" className="size-3.5" />
              illimité
            </span>
          )}
        </span>
        {runsHref && (
          <span className="ml-auto inline-flex items-center gap-1 text-accent-text">
            Voir les runs
            <ChevronRight aria-hidden="true" className="size-3.5" />
          </span>
        )}
      </div>
    </>
  );

  return (
    <div className="flex flex-col rounded-xl border border-border bg-surface transition-shadow hover:shadow-sm">
      {runsHref ? (
        <Link
          className="block rounded-t-xl p-5 transition-colors hover:bg-surface-2 focus:outline-none focus:ring-2 focus:ring-accent"
          href={runsHref}
        >
          {body}
        </Link>
      ) : (
        <div className="p-5">{body}</div>
      )}

      <div className="flex items-center gap-2 border-t border-border p-5 pt-3">
        <Link
          className="inline-flex items-center gap-1.5 rounded border border-border px-3 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-surface-2"
          href={`/admin/weekly-runs/${tpl.id}/modifier`}
        >
          <Edit2 aria-hidden="true" className="size-3.5" />
          Modifier
        </Link>
        {tpl.isActive && (
          <button
            className="inline-flex items-center gap-1.5 rounded border border-border px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:border-danger hover:text-danger disabled:opacity-40"
            disabled={deactivating}
            onClick={onDeactivate}
            type="button"
          >
            <PowerOff aria-hidden="true" className="size-3.5" />
            {deactivating ? "…" : "Désactiver"}
          </button>
        )}
      </div>
    </div>
  );
}

// ── Current run card ──────────────────────────────────────────────────────────

type CurrentRunCardProps = {
  run: AdminCurrentWeeklyRun;
  expanded: boolean;
  onToggle: () => void;
  // When set (template run history), the card title shows the ISO week and the
  // subtitle shows the seed instead of the template/game names.
  weekLabel?: string;
  // When set, renders a "Télécharger le seed" action for the run's generated multidata.
  onDownloadOutput?: () => void;
};

export function CurrentRunCard({ run, expanded, onToggle, weekLabel, onDownloadOutput }: CurrentRunCardProps) {
  return (
    <div className="rounded-xl border border-border bg-surface">
      <div className="flex items-center justify-between gap-3 p-4">
        <div className="flex items-center gap-3">
          <div
            className={[
              "size-2 shrink-0 rounded-full",
              run.status === "active" ? "bg-emerald-400" : "bg-muted-foreground/40",
            ].join(" ")}
            aria-hidden="true"
          />
          <div>
            <p className="text-sm font-medium text-foreground">
              {weekLabel ?? run.templateName ?? run.gameName}
            </p>
            <p className="text-xs text-muted-foreground">
              {weekLabel ? run.seed : run.gameName}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-3">
          <span className="flex items-center gap-1 text-xs text-muted-foreground">
            <Users aria-hidden="true" className="size-3.5" />
            {run.entryCount}
          </span>
          <span
            className={[
              "rounded-full px-2.5 py-0.5 text-xs font-medium",
              run.status === "active"
                ? "bg-emerald-500/15 text-emerald-400"
                : "bg-surface-2 text-muted-foreground",
            ].join(" ")}
          >
            {run.status === "active" ? "En cours" : "Terminé"}
          </span>
          {onDownloadOutput && (
            <button
              className="flex items-center gap-1 rounded px-2 py-1 text-xs text-accent-text transition-colors hover:bg-surface-2"
              onClick={onDownloadOutput}
              title="Télécharger le seed généré"
              type="button"
            >
              <Download aria-hidden="true" className="size-3.5" />
              Seed
            </button>
          )}
          <button
            aria-expanded={expanded}
            className="flex items-center gap-1 rounded px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-surface-2 hover:text-foreground"
            onClick={onToggle}
            type="button"
          >
            {expanded ? (
              <><ChevronDown aria-hidden="true" className="size-4" /> Masquer</>
            ) : (
              <><ChevronRight aria-hidden="true" className="size-4" /> Détails</>
            )}
          </button>
        </div>
      </div>

      {expanded && (
        <div className="border-t border-border px-4 pb-4 pt-3">
          {run.entries.length === 0 ? (
            <p className="text-xs text-muted-foreground">Aucun participant pour l&apos;instant.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="pb-2 pr-4 font-medium">Joueur</th>
                    <th className="pb-2 pr-4 font-medium">Tentative</th>
                    <th className="pb-2 pr-4 font-medium">Lancé</th>
                    <th className="pb-2 font-medium">Objectif</th>
                  </tr>
                </thead>
                <tbody>
                  {run.entries.map((entry) => (
                    <tr
                      className="border-b border-border/50 last:border-0"
                      key={`${entry.userId}-${entry.attemptNumber}`}
                    >
                      <td className="py-1.5 pr-4 text-foreground">{entry.displayName}</td>
                      <td className="py-1.5 pr-4 text-muted-foreground">#{entry.attemptNumber}</td>
                      <td className="py-1.5 pr-4">
                        {entry.launchedAt ? (
                          <span className="text-emerald-500">Oui</span>
                        ) : (
                          <span className="text-muted-foreground">Non</span>
                        )}
                      </td>
                      <td className="py-1.5">
                        {entry.goalReachedAt ? (
                          <span className="text-emerald-500">
                            {entry.completionTimeSeconds != null
                              ? formatTime(entry.completionTimeSeconds)
                              : "Oui"}
                          </span>
                        ) : (
                          <span className="text-muted-foreground">-</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
