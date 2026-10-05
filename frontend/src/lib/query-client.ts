import { QueryClient, type Query } from "@tanstack/react-query";

export const DEFAULT_STALE_TIME = 30_000; // 30 s - public catalog, session list
export const REALTIME_STALE_TIME = 2_000; // 2 s - live player state, slot progression
export const STATIC_STALE_TIME = Infinity; // legal pages, env-config data
export const SESSION_STALE_TIME = 60_000; // 60 s - session-level state polled less aggressively
export const DEFAULT_GC_TIME = 300_000; // 5 min (300 s) - default garbage collection window

/**
 * Story 33.27. A fetch function never throws (AC-API2): a failed read comes back as `null`, which TanStack keeps as
 * a success for the whole staleTime - so a page that failed once kept saying so, API back up, until a full reload.
 * A cached `null` is therefore refetched on the next mount and focus, whatever the staleTime; anything else keeps
 * the usual staleness rule. A read whose `null` means "nothing" (no membership...) costs one request per mount.
 */
export function refetchWhenFailed(query: Query): boolean | "always" {
  return query.state.data === null ? "always" : true;
}

export function makeQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: DEFAULT_STALE_TIME, // 30 s
        gcTime: DEFAULT_GC_TIME, // 300 s
        retry: 1,
        refetchOnMount: refetchWhenFailed,
        refetchOnWindowFocus: refetchWhenFailed,
      },
    },
  });
}
