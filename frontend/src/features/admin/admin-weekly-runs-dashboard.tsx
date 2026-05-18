"use client";

import { useQuery } from "@tanstack/react-query";
import { ChevronDown, ChevronRight, Edit2, Infinity, LayoutTemplate, Plus, PowerOff, RefreshCw, Timer, Users } from "lucide-react";
import Link from "next/link";
import { useState } from "react";

import {
  deactivateAdminWeeklyTemplate,
  fetchAdminCurrentWeeklyRuns,
  fetchAdminWeeklyTemplates,
  triggerAdminWeeklyRunsGeneration,
} from "./admin-weekly-runs-api";
import type { AdminCurrentWeeklyRun, AdminWeeklyTemplateListItem } from "./admin-weekly-runs-api";

async function fetchDashboardData(): Promise<{
  templates: AdminWeeklyTemplateListItem[];
  currentRuns: AdminCurrentWeeklyRun[];
} | null> {
  const [templatesPayload, currentRuns] = await Promise.all([
    fetchAdminWeeklyTemplates(),
    fetchAdminCurrentWeeklyRuns(),
  ]);
  if (!templatesPayload || !currentRuns) return null;
  return { templates: templatesPayload.data, currentRuns };
}

function formatTime(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  return `${h}h${String(m).padStart(2, "0")}`;
}

// ── Skeletons ─────────────────────────────────────────────────────────────────

function SkeletonCard() {
  return (
    <div className="animate-pulse rounded-xl border border-border bg-surface p-5">
      <div className="flex items-start justify-between">
        <div className="flex flex-col gap-2">
          <div className="h-4 w-32 rounded bg-surface-2" />
          <div className="h-3 w-20 rounded bg-surface-2" />
        </div>
        <div className="h-5 w-14 rounded-full bg-surface-2" />
      </div>
      <div className="mt-4 flex gap-2">
        <div className="h-7 w-20 rounded bg-surface-2" />
        <div className="h-7 w-20 rounded bg-surface-2" />
      </div>
    </div>
  );
}

// ── Empty state ───────────────────────────────────────────────────────────────

function EmptyTemplates() {
  return (
    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border bg-surface/50 px-6 py-16 text-center">
      <div className="mb-4 flex size-14 items-center justify-center rounded-full bg-surface-2">
        <LayoutTemplate aria-hidden="true" className="size-7 text-muted-foreground" />
      </div>
      <h3 className="font-heading text-base font-semibold text-foreground">
        Aucun template configuré
      </h3>
      <p className="mt-1.5 max-w-xs text-sm text-muted-foreground">
        Les templates définissent le jeu, la config YAML et les règles de participation pour chaque run hebdomadaire.
      </p>
      <Link
        className="mt-6 inline-flex items-center gap-2 rounded-lg bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
        href="/admin/weekly-runs/nouveau"
      >
        <Plus aria-hidden="true" className="size-4" />
        Créer le premier template
      </Link>
    </div>
  );
}

// ── Template card ─────────────────────────────────────────────────────────────

type TemplateCardProps = {
  tpl: AdminWeeklyTemplateListItem;
  deactivating: boolean;
  onDeactivate: () => void;
};

function TemplateCard({ tpl, deactivating, onDeactivate }: TemplateCardProps) {
  return (
    <div className="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 transition-shadow hover:shadow-sm">
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

      <div className="flex items-center gap-4 text-xs text-muted-foreground">
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
      </div>

      <div className="flex items-center gap-2 border-t border-border pt-3">
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
};

function CurrentRunCard({ run, expanded, onToggle }: CurrentRunCardProps) {
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
              {run.templateName ?? run.gameName}
            </p>
            <p className="text-xs text-muted-foreground">{run.gameName}</p>
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

// ── Dashboard ─────────────────────────────────────────────────────────────────

export function AdminWeeklyRunsDashboard() {
  const { data, isLoading, refetch } = useQuery({
    queryKey: ["admin-weekly-dashboard"],
    queryFn: fetchDashboardData,
    staleTime: 30_000,
    refetchInterval: 30_000,
  });
  const [deactivating, setDeactivating] = useState<string | null>(null);
  const [expandedRun, setExpandedRun] = useState<string | null>(null);
  const [generating, setGenerating] = useState(false);

  async function handleGenerate() {
    setGenerating(true);
    await triggerAdminWeeklyRunsGeneration();
    setGenerating(false);
    await refetch();
  }

  async function handleDeactivate(id: string) {
    setDeactivating(id);
    const ok = await deactivateAdminWeeklyTemplate(id);
    setDeactivating(null);
    if (ok) {
      await refetch();
    }
  }

  if (isLoading) {
    return (
      <div className="flex flex-col gap-8 p-6 md:p-8">
        <div className="flex items-center justify-between">
          <div className="flex flex-col gap-2">
            <div className="h-5 w-48 animate-pulse rounded bg-surface-2" />
            <div className="h-3.5 w-72 animate-pulse rounded bg-surface-2" />
          </div>
          <div className="h-9 w-36 animate-pulse rounded-lg bg-surface-2" />
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </div>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="flex items-center justify-center p-12">
        <div className="rounded-xl border border-danger/30 bg-danger/5 px-6 py-5 text-center">
          <p className="font-medium text-danger">Impossible de charger les données</p>
          <p className="mt-1 text-sm text-muted-foreground">Recharge la page pour réessayer.</p>
        </div>
      </div>
    );
  }

  const { templates, currentRuns } = data;
  const activeTemplates = templates.filter((t) => t.isActive);
  const inactiveTemplates = templates.filter((t) => !t.isActive);

  return (
    <div className="flex flex-col gap-10 p-6 md:p-8">

      {/* Templates section */}
      <section>
        <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 className="font-heading text-lg font-bold text-foreground">
              Templates hebdomadaires
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">
              {templates.length === 0
                ? "Aucun template configuré."
                : `${activeTemplates.length} actif${activeTemplates.length !== 1 ? "s" : ""} · ${inactiveTemplates.length} inactif${inactiveTemplates.length !== 1 ? "s" : ""}`}
            </p>
          </div>
          <Link
            className="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/admin/weekly-runs/nouveau"
          >
            <Plus aria-hidden="true" className="size-4" />
            Nouveau template
          </Link>
        </div>

        {templates.length === 0 ? (
          <EmptyTemplates />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {templates.map((tpl) => (
              <TemplateCard
                deactivating={deactivating === tpl.id}
                key={tpl.id}
                onDeactivate={() => void handleDeactivate(tpl.id)}
                tpl={tpl}
              />
            ))}
          </div>
        )}
      </section>

      {/* Current week runs */}
      {(currentRuns.length > 0 || activeTemplates.length > 0) && (
        <section>
          <div className="mb-4 flex items-center justify-between gap-4">
            <h2 className="font-heading text-lg font-bold text-foreground">
              Runs de la semaine en cours
            </h2>
            <button
              className="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-surface-2 disabled:opacity-50"
              disabled={generating}
              onClick={() => void handleGenerate()}
              type="button"
            >
              <RefreshCw aria-hidden="true" className={`size-4 ${generating ? "animate-spin" : ""}`} />
              {generating ? "Génération…" : "Générer maintenant"}
            </button>
          </div>
          {currentRuns.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Aucun run actif cette semaine. Clique sur «&nbsp;Générer maintenant&nbsp;» pour créer les runs des templates actifs.
            </p>
          ) : (
            <div className="flex flex-col gap-3">
              {currentRuns.map((run) => (
                <CurrentRunCard
                  expanded={expandedRun === run.weeklyRunId}
                  key={run.weeklyRunId}
                  onToggle={() =>
                    setExpandedRun((prev) =>
                      prev === run.weeklyRunId ? null : run.weeklyRunId,
                    )
                  }
                  run={run}
                />
              ))}
            </div>
          )}
        </section>
      )}
    </div>
  );
}
