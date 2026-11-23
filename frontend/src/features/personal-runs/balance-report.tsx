"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Scale, TriangleAlert } from "lucide-react";

import {
  fetchBalanceReport,
  startBalanceGeneration,
  type BalanceAlert,
  type BalanceReport,
  type BalanceRow,
  type BalanceScope,
  type SimulatedSlot,
} from "./balance-api";

/** Story 56.3: polled while the orchestrator generates, quiet otherwise. */
const GENERATING_REFRESH_MS = 5_000;

const ALERT_LABELS: Record<BalanceAlert, string> = {
  duration_over_median: "Plus de 2 fois la durée médiane",
  duration_over_target: "Plus long que la durée visée",
  hosted_over_fair: "Héberge plus de 2 fois sa part de la progression",
  hosted_over_half: "Héberge plus de la moitié de la progression des autres",
};

const SOURCE_LABELS: Record<string, string> = {
  measured: "mesuré",
  admin: "estimation admin",
  unknown: "rythme inconnu",
};

export function balanceQueryKey(scope: BalanceScope): readonly unknown[] {
  return ["balance-report", scope.kind, scope.kind === "run" ? scope.runId : scope.eventId];
}

function percent(share: number | null): string {
  return share === null ? "-" : `${Math.round(share * 1000) / 10} %`;
}

function hours(value: number | null): string {
  if (value === null) return "-";
  return value < 10 ? `${value.toFixed(1).replace(".", ",")} h` : `${Math.round(value)} h`;
}

export function alertLabel(alert: BalanceAlert): string {
  return ALERT_LABELS[alert];
}

/** The line saying where the report stands, or null when the table says it all. */
export function balanceStateLine(report: BalanceReport): string | null {
  switch (report.status) {
    case "none":
      return "Aucune génération à blanc pour l'instant.";
    case "generating":
      return "Génération à blanc en cours…";
    case "needs_files":
      return `Génération impossible sans fichier : ${report.missingFiles.join(", ")} demande${report.missingFiles.length > 1 ? "nt" : ""} une ROM à la génération.`;
    case "failed":
      return report.error ?? "La génération à blanc a échoué.";
    case "done":
      return null;
  }
}

type ViewProps = {
  report: BalanceReport;
  /** The player's own lines only: no summary of the party. */
  mine?: boolean;
};

/** Story 56.3: the balance report, one line per slot. Pure: the panel reads and refreshes it. */
export function BalanceReportView({ report, mine = false }: ViewProps) {
  const state = balanceStateLine(report);

  return (
    <div className="grid min-w-0 gap-3">
      {state !== null && <p className={report.status === "failed" || report.status === "needs_files" ? "text-sm text-danger" : "text-sm text-muted-foreground"}>{state}</p>}
      {report.stale && report.status !== "none" && (
        <p className="flex items-center gap-1.5 text-sm text-[color:var(--color-warning)]">
          <TriangleAlert aria-hidden="true" className="size-4 shrink-0" />À refaire : une inscription ou un YAML a changé depuis.
        </p>
      )}
      {report.status === "done" && !mine && (
        <p className="text-xs text-muted-foreground">
          {report.slotCount} slots. Durée médiane {hours(report.medianHours)}
          {report.targetHours !== null ? `, durée visée ${hours(report.targetHours)}` : ""}.
        </p>
      )}
      {report.status === "done" && !mine && report.simulation !== null && <SimulationSummary report={report} />}
      {report.status === "done" && report.slots.length > 0 && (
        <div className="min-w-0 overflow-x-auto">
          <table className="w-full min-w-[48rem] text-left text-sm">
            <thead className="text-xs text-muted-foreground">
              <tr>
                <th className="py-1 pr-3 font-medium">Joueur</th>
                <th className="py-1 pr-3 font-medium">Jeu</th>
                <th className="py-1 pr-3 font-medium">Taille</th>
                <th className="py-1 pr-3 font-medium">Durée estimée</th>
                <th className="py-1 pr-3 font-medium">Écart à la médiane</th>
                <th className="py-1 pr-3 font-medium">Progression des autres hébergée</th>
                <th className="py-1 font-medium">Déroulé simulé</th>
              </tr>
            </thead>
            <tbody>
              {report.slots.map((row) => (
                <BalanceLine key={row.slotName} row={row} />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function BalanceLine({ row }: { row: BalanceRow }) {
  return (
    <tr className="border-t border-border align-top">
      <td className="py-2 pr-3 text-foreground">
        {row.playerName}
        <span className="block text-xs text-muted-foreground">{row.slotName}</span>
      </td>
      <td className="py-2 pr-3 text-foreground">{row.gameName}</td>
      <td className="py-2 pr-3 text-foreground">{row.locations === null ? "-" : `${row.locations} emplacements`}</td>
      <td className="py-2 pr-3 text-foreground">
        {row.hours === null ? "durée inconnue" : hours(row.hours)}
        {row.hours !== null && <span className="block text-xs text-muted-foreground">{SOURCE_LABELS[row.durationSource] ?? row.durationSource}</span>}
      </td>
      <td className="py-2 pr-3 text-foreground">{row.medianGap === null ? "-" : `x${row.medianGap.toFixed(1).replace(".", ",")}`}</td>
      <td className="py-2 pr-3 text-foreground">
        {percent(row.othersShare)}
        <span className="block text-xs text-muted-foreground">part équitable {percent(row.fairShare)}</span>
        {row.alerts.length > 0 && (
          <ul className="mt-1 grid gap-0.5">
            {row.alerts.map((alert) => (
              <li className="flex items-start gap-1 text-xs text-[color:var(--color-warning)]" key={alert}>
                <TriangleAlert aria-hidden="true" className="mt-0.5 size-3 shrink-0" />
                {alertLabel(alert)}
              </li>
            ))}
          </ul>
        )}
      </td>
      <td className="py-2 text-foreground">
        <SimulatedCell simulation={row.simulation} />
      </td>
    </tr>
  );
}

/** Story 56.4: when the party ends, which slot it waits for, and what the model leaves out. */
function SimulationSummary({ report }: { report: BalanceReport }) {
  const simulation = report.simulation;
  if (simulation === null) return null;
  if (simulation.status === "missing") {
    return <p className="text-xs text-muted-foreground">Pas de déroulé simulé : refais la génération à blanc pour l&apos;obtenir.</p>;
  }
  if (simulation.status === "no_pace") {
    return <p className="text-xs text-muted-foreground">Pas de déroulé simulé : aucun jeu de la partie n&apos;a de rythme connu.</p>;
  }
  const critical = report.slots.find((row) => row.slotName === simulation.criticalSlot);
  return (
    <div className="grid gap-1 rounded-md border border-border bg-surface p-3 text-sm">
      <p className="text-foreground">
        Fin de partie simulée vers {hours(simulation.endHours)}
        {critical !== undefined ? (
          <>
            {" "}
            : la partie attend <span className="font-semibold">{critical.playerName}</span> ({critical.gameName}).
          </>
        ) : (
          "."
        )}
      </p>
      <p className="text-xs text-muted-foreground">
        Un ordre de grandeur pour comparer les slots : le modèle suit les sphères du spoiler au rythme de chacun et ignore
        les déplacements dans un jeu, les indices et les choix des joueurs.
      </p>
    </div>
  );
}

function SimulatedCell({ simulation }: { simulation: SimulatedSlot | null }) {
  if (simulation === null) return <>-</>;
  const waits = simulation.waitsOn.filter((w) => w.hours !== null && w.hours > 0);
  return (
    <>
      fin vers {hours(simulation.finishHours)}
      <span className="block text-xs text-muted-foreground">
        BK {hours(simulation.bkHours)}
        {simulation.paceAssumed ? " (rythme médian supposé)" : ""}
      </span>
      {waits.length > 0 && (
        <span className="block text-xs text-muted-foreground">
          attend {waits.map((w) => `${w.slotName} (${hours(w.hours)})`).join(", ")}
        </span>
      )}
    </>
  );
}

type PanelProps = {
  scope: BalanceScope;
  /** Who may run a dry generation: admins on an event, organisers on a run; never a player's own view. */
  canGenerate: boolean;
};

/**
 * Story 56.3: reads a scope's balance report and, for whoever may, runs a dry generation of the whole party: the
 * same generation as the real one, without a server. The report follows while it runs.
 */
export function BalancePanel({ scope, canGenerate }: PanelProps) {
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const { data } = useQuery({
    queryKey: balanceQueryKey(scope),
    queryFn: () => fetchBalanceReport(scope),
    staleTime: 30_000,
    refetchInterval: (query) => (query.state.data?.status === "generating" ? GENERATING_REFRESH_MS : false),
    retry: false,
  });

  if (data === undefined) {
    return <p className="text-sm text-muted-foreground">Chargement…</p>;
  }
  if (data === null) {
    return <p className="text-sm text-muted-foreground">Le rapport d&apos;équilibre est indisponible.</p>;
  }
  if (scope.kind === "mine" && data.status !== "done") {
    return <p className="text-sm text-muted-foreground">Les organisateurs n&apos;ont pas encore mesuré l&apos;équilibre de la partie.</p>;
  }

  async function generate() {
    if (scope.kind === "mine") return;
    setBusy(true);
    setError(null);
    const message = await startBalanceGeneration(scope);
    setBusy(false);
    setError(message);
    await queryClient.invalidateQueries({ queryKey: balanceQueryKey(scope) });
  }

  return (
    <div className="grid min-w-0 gap-3">
      <BalanceReportView mine={scope.kind === "mine"} report={data} />
      {canGenerate && scope.kind !== "mine" && (
        <div className="flex flex-wrap items-center gap-3">
          <button
            className="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text disabled:opacity-50"
            disabled={busy || data.status === "generating"}
            onClick={() => void generate()}
            type="button"
          >
            <Scale aria-hidden="true" className="size-4" />
            {data.status === "none" ? "Lancer une génération à blanc" : "Refaire la génération à blanc"}
          </button>
          <p className="text-xs text-muted-foreground">Génère la partie sans la lancer, avec les YAML enregistrés.</p>
        </div>
      )}
      {error !== null && <p className="text-sm text-danger">{error}</p>}
    </div>
  );
}
