"use client";

import { useEffect, useState } from "react";
import { Hourglass, Loader2 } from "lucide-react";

/**
 * Minutes since `since`, refreshed every 15 seconds. Read from the clock in callbacks only, never during render:
 * null until the first tick, and while there is nothing to count.
 */
export function useElapsedMinutes(since: string | null): number | null {
  const [minutes, setMinutes] = useState<number | null>(null);

  useEffect(() => {
    if (since === null) return;
    const start = new Date(since).getTime();
    if (Number.isNaN(start)) return;
    const tick = () => setMinutes(Math.max(0, Math.floor((Date.now() - start) / 60_000)));
    const frame = requestAnimationFrame(tick);
    const timer = setInterval(tick, 15_000);
    return () => {
      cancelAnimationFrame(frame);
      clearInterval(timer);
    };
  }, [since]);

  return since === null ? null : minutes;
}

/** The running test's line: since when it generates, once the clock has been read. */
export function runningTestLabel(minutes: number | null): string {
  if (minutes === null) return "Test de génération en cours";
  if (minutes < 1) return "Test de génération en cours depuis moins d'une minute";
  return `Test de génération en cours depuis ${minutes} min`;
}

type Props = {
  /** running: the orchestrator generates; queued: it waits for a container slot behind other tests. */
  state: "running" | "queued";
  startedAt: string | null;
};

/**
 * Story 38.33: a test generation that really runs, told apart from one that waits its turn. The page polls every
 * 10 seconds while a test is pending, so the line moves from the queue to the run, then to the verdict, on its own.
 */
export function ApworldTestProgress({ state, startedAt }: Props) {
  const minutes = useElapsedMinutes(state === "running" ? startedAt : null);

  if (state === "queued") {
    return (
      <p className="inline-flex items-center gap-2 text-sm text-muted-foreground" role="status">
        <Hourglass aria-hidden className="size-4" />
        En file d&apos;attente : un autre test occupe le runner.
      </p>
    );
  }

  return (
    <p className="inline-flex items-center gap-2 text-sm font-semibold text-accent-text" role="status">
      <Loader2 aria-hidden className="size-4 animate-spin" />
      {runningTestLabel(minutes)}
    </p>
  );
}
