"use client";

import { Download } from "lucide-react";
import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import Link from "next/link";

import {
  fetchWeeklyEntryPatches,
  launchWeeklyEntry,
  optInToWeeklyRun,
  isGoalReachedEvent,
} from "./weekly-runs-api";
import type { CurrentWeeklyRun } from "./weekly-runs-api";
import { WeeklyRunLeaderboard } from "./weekly-run-leaderboard";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { env } from "@/lib/env";

// ── Countdown ─────────────────────────────────────────────────────────────────

function computeTimeLeft(): string {
  const now = new Date();
  const dayOfWeek = now.getUTCDay(); // 0=Sun, 6=Sat
  const daysUntilSunday = dayOfWeek === 0 ? 0 : 7 - dayOfWeek;
  const deadline = new Date(Date.UTC(
    now.getUTCFullYear(),
    now.getUTCMonth(),
    now.getUTCDate() + daysUntilSunday,
    23, 59, 59,
  ));
  const diff = Math.max(0, Math.floor((deadline.getTime() - now.getTime()) / 1000));
  if (diff === 0) return "Terminé";
  const d = Math.floor(diff / 86400);
  const h = Math.floor((diff % 86400) / 3600);
  const m = Math.floor((diff % 3600) / 60);
  const s = diff % 60;
  if (d > 0) return `${d}j ${String(h).padStart(2, "0")}h${String(m).padStart(2, "0")}`;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

function Countdown() {
  const [timeLeft, setTimeLeft] = useState(() => computeTimeLeft());
  useEffect(() => {
    const id = setInterval(() => setTimeLeft(computeTimeLeft()), 1000);
    return () => clearInterval(id);
  }, []);
  return <span className="font-mono text-sm text-muted-foreground">{timeLeft}</span>;
}

// ── Copy button ───────────────────────────────────────────────────────────────

function CopyButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false);
  function handleCopy() {
    navigator.clipboard.writeText(value).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }).catch(() => {});
  }
  return (
    <button
      className="ml-2 rounded border border-border px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
      onClick={handleCopy}
      type="button"
    >
      {copied ? "Copié !" : "Copier"}
    </button>
  );
}

// ── Connection panel ──────────────────────────────────────────────────────────

function ConnectionPanel({
  host,
  port,
  password,
}: {
  host: string;
  port: number;
  password: string | null;
}) {
  return (
    <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4">
      <p className="mb-3 text-sm font-semibold text-emerald-400">
        Serveur Archipelago prêt
      </p>
      <div className="flex flex-col gap-2 font-mono text-sm">
        <div className="flex items-center">
          <span className="w-20 text-muted-foreground">Host</span>
          <span className="text-foreground">{host}</span>
          <CopyButton value={host} />
        </div>
        <div className="flex items-center">
          <span className="w-20 text-muted-foreground">Port</span>
          <span className="text-foreground">{port}</span>
          <CopyButton value={String(port)} />
        </div>
        {password && (
          <div className="flex items-center">
            <span className="w-20 text-muted-foreground">Password</span>
            <span className="text-foreground">{password}</span>
            <CopyButton value={password} />
          </div>
        )}
      </div>
    </div>
  );
}

// ── WeeklyRunCard ─────────────────────────────────────────────────────────────

type Props = {
  run: CurrentWeeklyRun;
  myUserId: string | null;
};

export function WeeklyRunCard({ run, myUserId }: Props) {
  const queryClient = useQueryClient();
  const [actionLoading, setActionLoading] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  const patchEntryId = run.myEntry?.entryId ?? null;
  const { data: patches = [] } = useQuery({
    queryKey: ["weekly-run-patches", run.weeklyRunId, patchEntryId],
    queryFn: async () => {
      if (!patchEntryId) return [];
      return fetchWeeklyEntryPatches(run.weeklyRunId, patchEntryId);
    },
    staleTime: DEFAULT_STALE_TIME,
    enabled: run.myEntry !== null,
  });

  // Subscribe to Mercure for real-time leaderboard updates
  useEffect(() => {
    if (!env.mercurePublicUrl) return;
    const url = new URL(env.mercurePublicUrl);
    url.searchParams.append("topic", `weekly-runs/${run.weeklyRunId}/leaderboard`);
    const es = new EventSource(url.toString());

    es.onmessage = (event: MessageEvent) => {
      try {
        const data: unknown = JSON.parse(event.data);
        if (isGoalReachedEvent(data)) {
          void queryClient.invalidateQueries({ queryKey: ["weekly-runs", "current"] });
        }
      } catch {
        // ignore parse errors
      }
    };

    return () => es.close();
  }, [run.weeklyRunId, queryClient]);

  function showToast(msg: string) {
    setToast(msg);
    setTimeout(() => setToast(null), 4000);
  }

  async function handleOptIn() {
    setActionLoading(true);
    const result = await optInToWeeklyRun(run.weeklyRunId);
    setActionLoading(false);
    if (result === null) {
      showToast("Erreur lors de l'inscription. Réessayez.");
      return;
    }
    if ("error" in result) {
      if (result.error === "max_attempts_reached") {
        showToast("Tu as atteint le nombre maximum de tentatives pour ce run.");
      } else {
        showToast(`Erreur : ${result.error}`);
      }
      return;
    }
    void queryClient.invalidateQueries({ queryKey: ["weekly-runs", "current"] });
  }

  async function handleLaunch() {
    if (!run.myEntry) return;
    setActionLoading(true);
    const result = await launchWeeklyEntry(run.weeklyRunId, run.myEntry.entryId);
    setActionLoading(false);
    if (result === null) {
      showToast("Erreur lors du lancement. Réessayez.");
      return;
    }
    if ("error" in result) {
      showToast(`Erreur : ${result.error}`);
      return;
    }
    void queryClient.invalidateQueries({ queryKey: ["weekly-runs", "current"] });
  }

  const { myEntry } = run;
  const isActive = run.status === "active";

  const patchSection = patches.length > 0 && myEntry !== null ? (
    <div className="flex flex-col gap-1.5">
      <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
        Fichiers patch
      </p>
      {patches.map((filename) => (
        <a
          key={filename}
          className="inline-flex w-fit items-center gap-1.5 text-sm text-accent-text hover:underline"
          download={filename}
          href={`${env.apiBaseUrl}/weekly-runs/${run.weeklyRunId}/entries/${myEntry.entryId}/patches/${filename}`}
        >
          <Download aria-hidden="true" className="size-3.5" />
          {filename}
        </a>
      ))}
    </div>
  ) : null;

  return (
    <div className="flex flex-col gap-5 rounded-xl border border-border bg-surface p-5 md:p-6">
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-accent-text">
            Semaine {run.weekNumber}
          </p>
          <h2 className="mt-0.5 font-heading text-lg font-bold text-foreground">
            {run.templateName ?? run.gameName}
          </h2>
          {run.templateName && (
            <p className="text-sm text-muted-foreground">{run.gameName}</p>
          )}
        </div>
        <div className="flex flex-col items-end gap-0.5">
          <span
            className={[
              "rounded-full px-2.5 py-0.5 text-xs font-medium",
              isActive
                ? "bg-emerald-500/15 text-emerald-400"
                : "bg-surface-2 text-muted-foreground",
            ].join(" ")}
          >
            {isActive ? "En cours" : "Terminé"}
          </span>
          {isActive && (
            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              <span>Fin dans</span>
              <Countdown />
            </div>
          )}
        </div>
      </div>

      {/* Toast */}
      {toast && (
        <div className="rounded border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger">
          {toast}
        </div>
      )}

      {/* Action area */}
      {isActive && myUserId && (
        <div>
          {myEntry === null && (
            <button
              className="rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
              disabled={actionLoading}
              onClick={() => void handleOptIn()}
              type="button"
            >
              {actionLoading ? "Inscription…" : "S'inscrire à ce run"}
            </button>
          )}

          {myEntry !== null && myEntry.connectionInfo === null && (
            <div className="flex flex-col gap-3">
              <button
                className="w-fit rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
                disabled={actionLoading}
                onClick={() => void handleLaunch()}
                type="button"
              >
                {actionLoading ? "Lancement…" : "Lancer ma partie"}
              </button>
              {patchSection}
            </div>
          )}

          {myEntry !== null && myEntry.connectionInfo !== null && (
            <div className="flex flex-col gap-3">
              <ConnectionPanel
                host={myEntry.connectionInfo.host}
                password={myEntry.connectionInfo.password}
                port={myEntry.connectionInfo.port}
              />
              {patchSection}
              <Link
                className="inline-flex w-fit items-center gap-1.5 rounded border border-border bg-surface px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:border-accent hover:text-foreground"
                href={`/runs-hebdo/${run.weeklyRunId}/ma-run`}
              >
                Suivre ma progression →
              </Link>
            </div>
          )}
        </div>
      )}

      {/* Leaderboard */}
      <WeeklyRunLeaderboard
        leaderboard={run.leaderboard}
        myEntryId={myEntry?.entryId ?? null}
        myUserId={myUserId}
        participants={run.participants}
      />
    </div>
  );
}
