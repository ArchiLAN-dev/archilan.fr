"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, History } from "lucide-react";
import Link from "next/link";
import { notFound } from "next/navigation";
import { useState } from "react";

import { CurrentRunCard } from "./admin-weekly-run-cards";
import {
  fetchAdminTemplateRuns,
  fetchAdminWeeklyTemplate,
} from "./admin-weekly-runs-api";
import type { AdminTemplateRun, AdminWeeklyTemplate } from "./admin-weekly-runs-api";

type TemplateDetailData = {
  template: AdminWeeklyTemplate;
  runs: AdminTemplateRun[];
};

async function fetchTemplateDetailData(templateId: string): Promise<TemplateDetailData | null | "not-found"> {
  const [template, runs] = await Promise.all([
    fetchAdminWeeklyTemplate(templateId),
    fetchAdminTemplateRuns(templateId),
  ]);
  if (template === null) return "not-found";
  if (!runs) return null;
  return { template, runs };
}

function weekLabel(run: AdminTemplateRun): string {
  return `Semaine ${run.weekYear}-S${String(run.weekNumber).padStart(2, "0")}`;
}

export function AdminWeeklyRunTemplateDetail({ templateId }: { templateId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ["admin-weekly-template-runs", templateId],
    queryFn: () => fetchTemplateDetailData(templateId),
    staleTime: 30_000,
    refetchInterval: 30_000,
  });
  const [expandedRun, setExpandedRun] = useState<string | null>(null);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-8 p-6 md:p-8">
        <div className="h-6 w-48 animate-pulse rounded bg-surface-2" />
        <div className="flex flex-col gap-3">
          <div className="h-16 animate-pulse rounded-xl bg-surface-2" />
          <div className="h-16 animate-pulse rounded-xl bg-surface-2" />
        </div>
      </div>
    );
  }

  if (data === "not-found") {
    notFound();
  }

  if (!data) {
    return (
      <div className="flex items-center justify-center p-12">
        <div className="rounded-xl border border-danger/30 bg-danger/5 px-6 py-5 text-center">
          <p className="font-medium text-danger">Impossible de charger ce template</p>
          <p className="mt-1 text-sm text-muted-foreground">Recharge la page pour réessayer.</p>
        </div>
      </div>
    );
  }

  const { template, runs } = data;

  return (
    <div className="flex flex-col gap-8 p-6 md:p-8">
      {/* Back link to the game page */}
      <Link
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
        href={`/admin/weekly-runs/jeu/${template.gameId}`}
      >
        <ArrowLeft aria-hidden="true" className="size-4" />
        {template.gameName}
      </Link>

      {/* Header */}
      <header>
        <h2 className="font-heading text-xl font-bold text-foreground">
          {template.name ?? <span className="italic text-muted-foreground">Template sans nom</span>}
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          {template.gameName} · {runs.length} run{runs.length !== 1 ? "s" : ""}
        </p>
      </header>

      {/* Runs (current + past) */}
      <section>
        <h3 className="mb-4 font-heading text-lg font-bold text-foreground">
          Historique des runs
        </h3>
        {runs.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border bg-surface/50 px-6 py-16 text-center">
            <div className="mb-4 flex size-14 items-center justify-center rounded-full bg-surface-2">
              <History aria-hidden="true" className="size-7 text-muted-foreground" />
            </div>
            <h4 className="font-heading text-base font-semibold text-foreground">
              Aucun run pour ce template
            </h4>
            <p className="mt-1.5 max-w-xs text-sm text-muted-foreground">
              Les runs apparaîtront ici dès que la génération hebdomadaire sera déclenchée.
            </p>
          </div>
        ) : (
          <div className="flex flex-col gap-3">
            {runs.map((run) => (
              <CurrentRunCard
                expanded={expandedRun === run.weeklyRunId}
                key={run.weeklyRunId}
                onToggle={() =>
                  setExpandedRun((prev) => (prev === run.weeklyRunId ? null : run.weeklyRunId))
                }
                run={run}
                weekLabel={weekLabel(run)}
              />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
