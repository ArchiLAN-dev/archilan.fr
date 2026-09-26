"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";

import {
  acknowledgeApworldIncident,
  APWORLD_INCIDENTS_QUERY_KEY,
  fetchApworldIncidents,
  fetchApworldSweepProgress,
  ignoreApworldIncident,
  resolveApworldIncident,
  type ApworldIncidentActionResult,
  type ApworldIncidentScope,
} from "./admin-apworld-health-api";
import { ApworldIncidentList } from "./apworld-incident-list";
import { ApworldSweepProgress } from "./apworld-sweep-progress";

const STALE_TIME = 15_000;

const TABS: { scope: ApworldIncidentScope; label: string; empty: string }[] = [
  { scope: "active", label: "En cours", empty: "Aucun apworld en échec." },
  { scope: "closed", label: "Historique", empty: "Aucun incident clos pour l'instant." },
];

/**
 * The apworld health page (story 38.3): every apworld whose default generation fails, who holds it and
 * since when. The alerts of story 38.2 lead here.
 */
export function AdminApworldHealthPage() {
  const queryClient = useQueryClient();
  const [scope, setScope] = useState<ApworldIncidentScope>("active");
  const [pendingId, setPendingId] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  // Story 38.9: how far the rolling test has come on the image in use.
  const { data: sweepProgress } = useQuery({
    queryKey: [...APWORLD_INCIDENTS_QUERY_KEY, "sweep-progress"],
    queryFn: fetchApworldSweepProgress,
  });

  const { data: incidents, isLoading, isError } = useQuery({
    queryKey: [...APWORLD_INCIDENTS_QUERY_KEY, "list", scope],
    queryFn: async () => {
      const result = await fetchApworldIncidents(scope);
      if (result === null) throw new Error("apworld incidents unavailable");
      return result;
    },
    staleTime: STALE_TIME,
  });

  async function run(id: string, action: (id: string) => Promise<ApworldIncidentActionResult>) {
    setPendingId(id);
    setActionError(null);
    const result = await action(id);
    if (!result.ok) setActionError(result.message);
    await queryClient.invalidateQueries({ queryKey: APWORLD_INCIDENTS_QUERY_KEY });
    setPendingId(null);
  }

  const tab = TABS.find((t) => t.scope === scope) ?? TABS[0];

  return (
    <section className="grid w-full min-w-0 grid-cols-1 gap-6 px-4 py-10">
      <header className="grid gap-1">
        <h1 className="font-heading text-2xl font-bold text-foreground">Santé des apworlds</h1>
        <p className="text-sm text-muted-foreground">
          Les apworlds dont la génération par défaut échoue. Un incident se résout tout seul quand le test repasse au
          vert ou quand le jeu change d&apos;apworld.
        </p>
      </header>

      <ApworldSweepProgress progress={sweepProgress ?? null} />

      <div className="flex flex-wrap gap-2 border-b border-border" role="tablist">
        {TABS.map((t) => (
          <button
            aria-selected={scope === t.scope}
            className={`-mb-px min-h-10 border-b-2 px-4 text-sm font-semibold transition-colors ${scope === t.scope ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"}`}
            key={t.scope}
            onClick={() => setScope(t.scope)}
            role="tab"
            type="button"
          >
            {t.label}
          </button>
        ))}
      </div>

      {actionError !== null && (
        <p className="rounded-md border border-danger/40 bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">
          {actionError}
        </p>
      )}

      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" />
          Chargement…
        </p>
      ) : isError || incidents === undefined ? (
        <p className="rounded-md border border-danger/40 bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">
          Les incidents n&apos;ont pas pu être chargés. Réessaie dans un instant.
        </p>
      ) : (
        <ApworldIncidentList
          emptyMessage={tab.empty}
          incidents={incidents}
          onAcknowledge={(id) => void run(id, acknowledgeApworldIncident)}
          onIgnore={(id) => void run(id, ignoreApworldIncident)}
          onResolve={(id) => void run(id, resolveApworldIncident)}
          pendingId={pendingId}
        />
      )}
    </section>
  );
}
