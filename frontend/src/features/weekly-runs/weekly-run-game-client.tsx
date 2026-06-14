"use client";

import { ArrowLeft, ChevronDown, Download, Loader2, Settings2 } from "lucide-react";
import { load as loadYaml } from "js-yaml";
import Image from "next/image";
import Link from "next/link";
import { use, useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { useAuth } from "@/features/auth/auth-context";
import { getAccountMembership } from "@/features/payments/membership-api";
import { MembershipNotice } from "./weekly-runs-client-page";
import {
  downloadPatch,
  fetchCurrentWeeklyRuns,
  fetchWeeklyEntryPatches,
  isGoalReachedEvent,
  launchWeeklyEntry,
  optInToWeeklyRun,
  relaunchWeeklyEntry,
} from "./weekly-runs-api";
import type { CurrentWeeklyRun, WeeklyRunLeaderboardEntry } from "./weekly-runs-api";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { env } from "@/lib/env";

// ── Helpers ───────────────────────────────────────────────────────────────────

function slugify(name: string): string {
  return name
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function formatTime(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  if (h > 0) return `${h}h ${String(m).padStart(2, "0")}m ${String(s).padStart(2, "0")}s`;
  return `${m}m ${String(s).padStart(2, "0")}s`;
}

// ── CopyButton ────────────────────────────────────────────────────────────────

function CopyButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false);
  function handleCopy() {
    navigator.clipboard.writeText(value).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }).catch(() => undefined);
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

// ── YAML options viewer ───────────────────────────────────────────────────────

type ApOptionValue =
  | boolean
  | number
  | string
  | number[]
  | Record<string, number>;

function isWeightedDict(v: unknown): v is Record<string, number> {
  if (typeof v !== "object" || v === null || Array.isArray(v)) return false;
  return Object.values(v).every((w) => typeof w === "number");
}

function isRange(v: unknown): v is [number, number] {
  return (
    Array.isArray(v) &&
    v.length === 2 &&
    typeof v[0] === "number" &&
    typeof v[1] === "number"
  );
}

function formatOptionName(key: string): string {
  return key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function OptionValue({ value }: { value: ApOptionValue }) {
  if (isWeightedDict(value)) {
    const entries = Object.entries(value);
    const total = entries.reduce((s, [, w]) => s + w, 0);
    if (total === 0) return <span className="text-muted-foreground">-</span>;

    return (
      <ul className="mt-1 flex flex-col gap-1.5">
        {entries
          .sort(([, a], [, b]) => b - a)
          .map(([label, weight]) => {
            const pct = Math.round((weight / total) * 100);
            return (
              <li className="flex items-center gap-2" key={label}>
                <div className="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-surface-2">
                  <div
                    className="h-full rounded-full bg-accent-text"
                    style={{ width: `${pct}%` }}
                  />
                </div>
                <span className="w-9 shrink-0 text-right text-xs text-muted-foreground">
                  {pct}%
                </span>
                <span className="text-xs text-foreground">{label}</span>
              </li>
            );
          })}
      </ul>
    );
  }

  if (isRange(value)) {
    return (
      <span className="text-foreground">
        entre <span className="font-mono">{value[0]}</span> et{" "}
        <span className="font-mono">{value[1]}</span>
      </span>
    );
  }

  if (typeof value === "boolean") {
    return (
      <span className={value ? "text-emerald-400" : "text-muted-foreground"}>
        {value ? "Oui" : "Non"}
      </span>
    );
  }

  return <span className="font-mono text-foreground">{String(value)}</span>;
}

function YamlOptionsViewer({
  yamlConfig,
  gameName,
}: {
  yamlConfig: string;
  gameName: string;
}) {
  const [open, setOpen] = useState(false);

  let gameOptions: Record<string, ApOptionValue> | null = null;
  try {
    const doc = loadYaml(yamlConfig);
    if (typeof doc === "object" && doc !== null) {
      const raw = (doc as Record<string, unknown>)[gameName];
      if (typeof raw === "object" && raw !== null && !Array.isArray(raw)) {
        gameOptions = raw as Record<string, ApOptionValue>;
      }
    }
  } catch {
    // unparseable YAML - hide the viewer silently
  }

  if (!gameOptions || Object.keys(gameOptions).length === 0) return null;

  const entries = Object.entries(gameOptions);

  return (
    <div className="border-t border-border">
      <button
        className="flex w-full items-center justify-between px-5 py-3 text-left transition-colors hover:bg-surface-2/50"
        onClick={() => { setOpen((o) => !o); }}
        type="button"
      >
        <span className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
          <Settings2 aria-hidden className="size-3.5" />
          Configuration de la catégorie
        </span>
        <ChevronDown
          aria-hidden
          className={["size-4 text-muted-foreground transition-transform", open ? "rotate-180" : ""].join(" ")}
        />
      </button>

      {open && (
        <div className="border-t border-border px-5 py-4">
          <dl className="grid gap-4 sm:grid-cols-2">
            {entries.map(([key, value]) => (
              <div key={key}>
                <dt className="text-xs font-semibold text-muted-foreground">
                  {formatOptionName(key)}
                </dt>
                <dd className="mt-0.5 text-sm">
                  <OptionValue value={value} />
                </dd>
              </div>
            ))}
          </dl>
        </div>
      )}
    </div>
  );
}

// ── Leaderboard table ─────────────────────────────────────────────────────────

const RANK_COLORS = ["text-amber-400", "text-slate-400", "text-amber-700/80"];

type LeaderboardTableProps = {
  entries: WeeklyRunLeaderboardEntry[];
  myUserId: string | null;
  myEntryId: string | null;
  valueLabel: string;
  getValue: (entry: WeeklyRunLeaderboardEntry) => string;
};

function LeaderboardTable({ entries, myUserId, myEntryId, valueLabel, getValue }: LeaderboardTableProps) {
  if (entries.length === 0) {
    return (
      <p className="py-2 text-sm italic text-muted-foreground">
        Aucun goal atteint pour l&apos;instant.
      </p>
    );
  }

  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-border text-left">
          <th className="w-8 pb-2 pr-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            #
          </th>
          <th className="pb-2 pr-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Joueur
          </th>
          <th className="pb-2 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            {valueLabel}
          </th>
        </tr>
      </thead>
      <tbody>
        {entries.map((entry, i) => {
          const isMe = entry.entryId === myEntryId || entry.userId === myUserId;
          return (
            <tr
              className={["border-b border-border/40 last:border-0", isMe ? "bg-accent/5" : ""].join(" ")}
              key={entry.entryId}
            >
              <td className={["py-2.5 pr-3 font-mono font-bold", RANK_COLORS[i] ?? "text-muted-foreground"].join(" ")}>
                {i + 1}
              </td>
              <td className="py-2.5 pr-3 text-foreground">
                {entry.displayName ?? "Joueur inconnu"}
                {isMe && (
                  <span className="ml-2 text-xs text-accent-text">(moi)</span>
                )}
              </td>
              <td className="py-2.5 text-right font-mono text-muted-foreground">
                {getValue(entry)}
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}

// ── Dual leaderboard (Temps + Checks) ────────────────────────────────────────

type DualLeaderboardProps = {
  leaderboard: CurrentWeeklyRun["leaderboard"];
  myUserId: string | null;
  myEntryId: string | null;
};

function DualLeaderboard({ leaderboard, myUserId, myEntryId }: DualLeaderboardProps) {
  const [tab, setTab] = useState<"fastest" | "checks">("fastest");

  const hasAny = leaderboard.fastest.length > 0 || leaderboard.fewestChecks.length > 0;

  if (!hasAny) {
    return (
      <p className="py-2 text-sm italic text-muted-foreground">
        Aucun goal atteint pour l&apos;instant.
      </p>
    );
  }

  return (
    <div>
      {/* Mobile: tabs to swap between the two leaderboards */}
      <div className="mb-4 flex gap-1 rounded-lg bg-surface-2 p-1 sm:hidden">
        {(["fastest", "checks"] as const).map((t) => (
          <button
            className={[
              "flex-1 rounded-md py-1.5 text-xs font-semibold transition-colors",
              tab === t
                ? "bg-surface text-foreground shadow-sm"
                : "text-muted-foreground hover:text-foreground",
            ].join(" ")}
            key={t}
            onClick={() => { setTab(t); }}
            type="button"
          >
            {t === "fastest" ? "Meilleur temps" : "Moins de checks"}
          </button>
        ))}
      </div>

      {/* Mobile: single active tab */}
      <div className="sm:hidden">
        {tab === "fastest" ? (
          <LeaderboardTable
            entries={leaderboard.fastest}
            getValue={(e) => e.completionTimeSeconds != null ? formatTime(e.completionTimeSeconds) : "-"}
            myEntryId={myEntryId}
            myUserId={myUserId}
            valueLabel="Temps"
          />
        ) : (
          <LeaderboardTable
            entries={leaderboard.fewestChecks}
            getValue={(e) => e.checksTotal != null ? String(e.checksTotal) : "-"}
            myEntryId={myEntryId}
            myUserId={myUserId}
            valueLabel="Checks"
          />
        )}
      </div>

      {/* Desktop: side by side */}
      <div className="hidden gap-6 sm:grid sm:grid-cols-2">
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Meilleur temps
          </p>
          <LeaderboardTable
            entries={leaderboard.fastest}
            getValue={(e) => e.completionTimeSeconds != null ? formatTime(e.completionTimeSeconds) : "-"}
            myEntryId={myEntryId}
            myUserId={myUserId}
            valueLabel="Temps"
          />
        </div>
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Moins de checks
          </p>
          <LeaderboardTable
            entries={leaderboard.fewestChecks}
            getValue={(e) => e.checksTotal != null ? String(e.checksTotal) : "-"}
            myEntryId={myEntryId}
            myUserId={myUserId}
            valueLabel="Checks"
          />
        </div>
      </div>
    </div>
  );
}

// ── Category section ──────────────────────────────────────────────────────────

type CategorySectionProps = {
  run: CurrentWeeklyRun;
  myUserId: string | null;
  canParticipate: boolean;
};

function CategorySection({ run, myUserId, canParticipate }: CategorySectionProps) {
  const queryClient = useQueryClient();
  const [actionLoading, setActionLoading] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  const isActive = run.status === "active";
  const categoryName = run.templateName ?? `Semaine ${run.weekNumber}`;
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
      } catch { /* ignore */ }
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
    if (result === null) { showToast("Erreur lors de l'inscription. Réessayez."); return; }
    if ("error" in result) {
      showToast(result.error === "max_attempts_reached"
        ? "Nombre maximum de tentatives atteint."
        : `Erreur : ${result.error}`);
      return;
    }
    void queryClient.invalidateQueries({ queryKey: ["weekly-runs", "current"] });
  }

  async function handleLaunch() {
    if (!run.myEntry) return;
    setActionLoading(true);
    const result = await launchWeeklyEntry(run.weeklyRunId, run.myEntry.entryId);
    setActionLoading(false);
    if (result === null) { showToast("Erreur lors du lancement. Réessayez."); return; }
    if ("error" in result) { showToast(`Erreur : ${result.error}`); return; }
    void queryClient.invalidateQueries({ queryKey: ["weekly-runs", "current"] });
    void queryClient.invalidateQueries({ queryKey: ["weekly-run-patches", run.weeklyRunId] });
  }

  async function handleRelaunch() {
    const sessionId = run.myEntry?.externalSessionId ?? null;
    if (sessionId === null) return;
    setActionLoading(true);
    const ok = await relaunchWeeklyEntry(sessionId);
    setActionLoading(false);
    if (!ok) { showToast("Erreur lors de la relance. Réessayez."); return; }
    void queryClient.invalidateQueries({ queryKey: ["weekly-runs", "current"] });
  }

  const { myEntry } = run;
  const myEntryId = myEntry?.entryId ?? null;

  return (
    <section className="overflow-hidden rounded-xl border border-border bg-surface">
      {/* Category header */}
      <div className="flex items-center justify-between gap-4 border-b border-border bg-surface-2/50 px-5 py-3">
        <div>
          <h2 className="font-heading text-base font-bold text-foreground">
            {categoryName}
          </h2>
          {run.templateName && (
            <p className="text-xs text-muted-foreground">
              Semaine {run.weekNumber} · {run.weekYear}
            </p>
          )}
        </div>
        <div className="flex items-center gap-3 shrink-0">
          <span className="text-sm text-muted-foreground">
            {run.participants.length} joueur{run.participants.length !== 1 ? "s" : ""}
          </span>
          <span
            className={[
              "rounded-full px-2.5 py-0.5 text-xs font-semibold",
              isActive
                ? "bg-emerald-500/15 text-emerald-400"
                : "bg-surface-2 text-muted-foreground",
            ].join(" ")}
          >
            {isActive ? "En cours" : "Terminé"}
          </span>
        </div>
      </div>

      {/* Leaderboard */}
      <div className="px-5 py-4">
        <DualLeaderboard
          leaderboard={run.leaderboard}
          myEntryId={myEntryId}
          myUserId={myUserId}
        />
      </div>

      {/* YAML config viewer - visible to all members */}
      {run.yamlConfig && (
        <YamlOptionsViewer gameName={run.gameName} yamlConfig={run.yamlConfig} />
      )}

      {/* Participation - only when active, authenticated, and a member/admin */}
      {isActive && myUserId && canParticipate && (
        <div className="border-t border-border px-5 py-4">
          {toast && (
            <div className="mb-3 rounded border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger">
              {toast}
            </div>
          )}

          {myEntry === null && (
            <button
              className="rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
              disabled={actionLoading}
              onClick={() => void handleOptIn()}
              type="button"
            >
              {actionLoading ? "Inscription…" : "Participer"}
            </button>
          )}

          {myEntry !== null && myEntry.connectionInfo === null && (
            run.isGenerated ? (
              <div className="flex flex-col gap-3">
                <button
                  className="w-fit rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
                  disabled={actionLoading}
                  onClick={() => void handleLaunch()}
                  type="button"
                >
                  {actionLoading ? "Lancement…" : "Lancer ma partie"}
                </button>
                {patches.length > 0 && (
                  <div className="flex flex-wrap gap-3">
                    {patches.map((filename) => (
                      <button
                        className="inline-flex items-center gap-1.5 text-sm text-accent-text hover:underline"
                        key={filename}
                        onClick={() => { void downloadPatch(run.weeklyRunId, myEntry.entryId, filename); }}
                        type="button"
                      >
                        <Download aria-hidden className="size-3.5" />
                        {filename}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            ) : (
              <div className="flex items-center gap-2.5 rounded border border-border bg-surface-2/50 px-4 py-3 text-sm text-muted-foreground">
                <Loader2 aria-hidden className="size-4 shrink-0 animate-spin text-accent-text" />
                <span>
                  Génération en cours… le monde de la semaine est en préparation.
                  Le lancement se débloquera automatiquement dès qu&apos;il sera prêt.
                </span>
              </div>
            )
          )}

          {myEntry !== null && myEntry.connectionInfo !== null && (() => {
            // Story 17.13: the container may have been stopped for inactivity (idle/stopped/crashed).
            // Only show the live connection info when it is actually running; otherwise offer a relaunch.
            // A null status is a pre-17.13 entry with no Session row yet — treat it as running.
            const status = myEntry.sessionStatus;
            const isRunning = status === "running" || status === null;
            const isRestarting = status === "restarting";
            const isRelaunchable = status === "idle" || status === "stopped" || status === "crashed";

            return (
              <div className="flex flex-col gap-3">
                {isRunning && (
                  <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4">
                    <p className="mb-3 text-sm font-semibold text-emerald-400">Serveur prêt</p>
                    <div className="flex flex-col gap-2 font-mono text-sm">
                      <div className="flex items-center">
                        <span className="w-20 text-muted-foreground">Host</span>
                        <span className="text-foreground">{myEntry.connectionInfo.host}</span>
                        <CopyButton value={myEntry.connectionInfo.host} />
                      </div>
                      <div className="flex items-center">
                        <span className="w-20 text-muted-foreground">Port</span>
                        <span className="text-foreground">{myEntry.connectionInfo.port}</span>
                        <CopyButton value={String(myEntry.connectionInfo.port)} />
                      </div>
                      {myEntry.connectionInfo.password && (
                        <div className="flex items-center">
                          <span className="w-20 text-muted-foreground">Password</span>
                          <span className="text-foreground">{myEntry.connectionInfo.password}</span>
                          <CopyButton value={myEntry.connectionInfo.password} />
                        </div>
                      )}
                    </div>
                  </div>
                )}

                {isRestarting && (
                  <div className="flex items-center gap-2.5 rounded-lg border border-border bg-surface-2/50 px-4 py-3 text-sm text-muted-foreground">
                    <Loader2 aria-hidden className="size-4 shrink-0 animate-spin text-accent-text" />
                    <span>Relance du serveur en cours…</span>
                  </div>
                )}

                {isRelaunchable && (
                  <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4">
                    <p className="mb-3 text-sm text-muted-foreground">
                      Le serveur a été mis en pause après une période d&apos;inactivité. Relance-le pour
                      reprendre ta partie là où elle s&apos;était arrêtée.
                    </p>
                    <button
                      className="rounded bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
                      disabled={actionLoading}
                      onClick={() => void handleRelaunch()}
                      type="button"
                    >
                      {actionLoading ? "Relance…" : "Relancer ma partie"}
                    </button>
                  </div>
                )}

                {patches.length > 0 && (
                  <div className="flex flex-wrap gap-3">
                    {patches.map((filename) => (
                      <button
                        className="inline-flex items-center gap-1.5 text-sm text-accent-text hover:underline"
                        key={filename}
                        onClick={() => { void downloadPatch(run.weeklyRunId, myEntry.entryId, filename); }}
                        type="button"
                      >
                        <Download aria-hidden className="size-3.5" />
                        {filename}
                      </button>
                    ))}
                  </div>
                )}
                <Link
                  className="inline-flex w-fit items-center gap-1.5 rounded border border-border px-4 py-2 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                  href={`/runs-hebdo/${run.weeklyRunId}/ma-run`}
                >
                  Suivre ma progression →
                </Link>
              </div>
            );
          })()}
        </div>
      )}
    </section>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

type Props = {
  params: Promise<{ gameSlug: string }>;
};

export function WeeklyRunGameClientPage({ params }: Props) {
  const { gameSlug } = use(params);
  const { user, loading } = useAuth();

  const isAdmin = user?.roles.includes("ROLE_ADMIN") === true;

  const { data: membership, isLoading: membershipLoading } = useQuery({
    queryKey: ["account-membership"],
    queryFn: getAccountMembership,
    staleTime: DEFAULT_STALE_TIME,
    enabled: Boolean(user) && !isAdmin,
  });

  // Members (and admins) can participate; everyone else can browse but not join.
  const canParticipate = isAdmin || membership?.status === "active";

  // The list endpoint is public (optional auth) - everyone can browse the runs.
  const { data: runs = [], isLoading: runsLoading } = useQuery({
    queryKey: ["weekly-runs", "current"],
    queryFn: fetchCurrentWeeklyRuns,
    staleTime: DEFAULT_STALE_TIME,
    // Poll fast while a relaunch is in flight so the page flips back to "Serveur prêt" on its own
    // (restarting → running); otherwise a slow background refresh is enough. (Story 17.13)
    refetchInterval: (query) =>
      (query.state.data ?? []).some((r) => r.myEntry?.sessionStatus === "restarting") ? 3_000 : 60_000,
  });

  if (loading || (user && !isAdmin && membershipLoading) || runsLoading) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-border border-t-accent" />
      </div>
    );
  }

  const gameRuns = runs.filter((r) => slugify(r.gameName) === gameSlug);
  const gameName = gameRuns[0]?.gameName ?? gameSlug;
  const coverImageUrl = gameRuns[0]?.coverImageUrl ?? null;
  const totalParticipants = new Set(
    gameRuns.flatMap((r) => r.participants.map((p) => p.userId)),
  ).size;

  if (gameRuns.length === 0) {
    return (
      <div className="py-16 text-center">
        <p className="text-lg font-semibold text-foreground">Jeu introuvable</p>
        <p className="mt-2 text-sm text-muted-foreground">
          Aucun run actif pour ce jeu cette semaine.
        </p>
        <Link
          className="mt-6 inline-flex items-center gap-2 rounded bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
          href="/runs-hebdo"
        >
          <ArrowLeft aria-hidden className="size-4" />
          Retour aux jeux
        </Link>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-8">
      <nav className="text-sm text-muted-foreground">
        <Link
          className="inline-flex items-center gap-1 hover:text-foreground"
          href="/runs-hebdo"
        >
          <ArrowLeft aria-hidden className="size-3.5" />
          Retour aux jeux
        </Link>
      </nav>

      {/* Game header - speedrun.com style */}
      <header className="flex items-center gap-5">
        {coverImageUrl && (
          <div className="relative h-24 w-16 shrink-0 overflow-hidden rounded-lg border border-border">
            <Image
              alt={gameName}
              className="object-cover"
              fill
              sizes="64px"
              src={coverImageUrl}
            />
          </div>
        )}
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-accent-text">
            Runs hebdomadaires
          </p>
          <h1 className="mt-0.5 font-heading text-3xl font-bold text-foreground">
            {gameName}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {totalParticipants} participant{totalParticipants !== 1 ? "s" : ""}
            {" · "}
            {gameRuns.length} catégorie{gameRuns.length !== 1 ? "s" : ""}
          </p>
        </div>
      </header>

      {!canParticipate && <MembershipNotice loggedIn={Boolean(user)} />}

      {/* Categories */}
      <div className="flex flex-col gap-4">
        {gameRuns.map((run) => (
          <CategorySection
            canParticipate={canParticipate}
            key={run.weeklyRunId}
            myUserId={user?.id ?? null}
            run={run}
          />
        ))}
      </div>
    </div>
  );
}
