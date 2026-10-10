"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { BellOff, BellRing } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchRunNudges, muteRunNudges, nudgeRunPlayer, type RunNudgePlayer, type RunNudges } from "./run-nudges-api";

export const runNudgesKey = (runId: string) => ["run-nudges", runId];

/** Story 43.12: who the caller may nudge, read only while the run is being played. */
export function useRunNudges(runId: string, enabled: boolean) {
  return useQuery({
    queryKey: runNudgesKey(runId),
    queryFn: () => fetchRunNudges(runId),
    enabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
}

export function alreadyNudgedLabel(hoursAgo: number): string {
  return `Déjà relancé il y a ${hoursAgo} h`;
}

/**
 * « Relancer » next to a co-player idle for two days (story 43.12). Once a day per player whoever sends it: after
 * that, when it went out. Nothing for oneself, a player who played lately or who turned nudges off.
 */
export function RunNudgeButton({ runId, player, isMe }: { runId: string; player: RunNudgePlayer | undefined; isMe: boolean }) {
  const queryClient = useQueryClient();
  const [sent, setSent] = useState(false);
  const [refusedHoursAgo, setRefusedHoursAgo] = useState<number | null>(null);
  const [failed, setFailed] = useState(false);

  if (player === undefined || isMe || !player.idle || player.muted) return null;

  if (sent) {
    return <span className="shrink-0 text-xs font-medium text-accent-text">Relancé</span>;
  }
  const hoursAgo = refusedHoursAgo ?? (player.canNudge ? null : player.nudgedHoursAgo);
  if (hoursAgo !== null) {
    return <span className="shrink-0 text-xs text-muted-foreground">{alreadyNudgedLabel(hoursAgo)}</span>;
  }
  if (!player.canNudge) return null;
  const userId = player.userId;

  async function nudge() {
    const result = await nudgeRunPlayer(runId, userId);
    if (result.ok) {
      setSent(true);
    } else if (result.hoursAgo !== null) {
      setRefusedHoursAgo(result.hoursAgo);
    } else {
      setFailed(true);
    }
    void queryClient.invalidateQueries({ queryKey: runNudgesKey(runId) });
  }

  return (
    <button
      className="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text"
      onClick={() => {
        void nudge();
      }}
      title={failed ? "La relance n'est pas partie, réessaie." : "Pas de check depuis deux jours : un petit rappel, une fois par jour au plus."}
      type="button"
    >
      <BellRing aria-hidden className="size-3" />
      {failed ? "Réessayer" : "Relancer"}
    </button>
  );
}

/** The caller's own switch: no more nudges for this run (story 43.12). */
export function RunNudgeMute({ runId, nudges }: { runId: string; nudges: RunNudges }) {
  const queryClient = useQueryClient();
  const [failed, setFailed] = useState(false);

  async function toggle() {
    const ok = await muteRunNudges(runId, !nudges.muted);
    setFailed(!ok);
    if (!ok) return;
    const next: RunNudges = { ...nudges, muted: !nudges.muted };
    queryClient.setQueryData(runNudgesKey(runId), next);
  }

  return (
    <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-3">
      <button
        aria-pressed={nudges.muted}
        className="inline-flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
        onClick={() => {
          void toggle();
        }}
        type="button"
      >
        {nudges.muted ? <BellRing aria-hidden className="size-3.5" /> : <BellOff aria-hidden className="size-3.5" />}
        {nudges.muted ? "Accepter de nouveau les relances pour cette partie" : "Ne plus me relancer pour cette partie"}
      </button>
      {failed ? (
        <span className="text-xs text-danger" role="alert">
          Réglage non enregistré, réessaie.
        </span>
      ) : null}
    </div>
  );
}
