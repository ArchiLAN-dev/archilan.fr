"use client";

import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { useAuth } from "@/features/auth/auth-context";
import { fetchMyRuns, type MyRunsData } from "./personal-runs-api";
import type { PersonalRunStatus } from "./types";

const OVER: readonly PersonalRunStatus[] = ["completed", "cancelled"];

/** Story 43.21: the member's runs still going (owned or joined), leaving out the finished, cancelled and archived. */
export function liveRunCount(runs: MyRunsData): number {
  const ids = new Set<string>();
  for (const run of [...runs.owned, ...runs.joined]) {
    if (!OVER.includes(run.status) && run.archived !== true) ids.add(run.id);
  }
  return ids.size;
}

/**
 * Story 43.21: that count beside « Mes parties » in the site header, for a signed-in member - under the list page's
 * query key, so a run created, joined or archived shows here at once. Nothing when there is none.
 */
export function MyRunsCount() {
  const { user, loading } = useAuth();
  const signedIn = user !== null && !loading;
  const { data } = useQuery({ queryKey: ["personal-runs", "mine"], queryFn: fetchMyRuns, staleTime: DEFAULT_STALE_TIME, retry: false, enabled: signedIn });

  const count = signedIn && data ? liveRunCount(data) : 0;
  if (count === 0) return null;
  return (
    <span className="ml-1.5 rounded-full bg-accent/15 px-2 py-0.5 text-xs font-semibold text-accent-text" title={`${count} en cours`}>
      {count}
      <span className="sr-only"> en cours</span>
    </span>
  );
}
