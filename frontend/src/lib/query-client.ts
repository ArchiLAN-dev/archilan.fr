import { QueryClient } from "@tanstack/react-query";

export const DEFAULT_STALE_TIME = 30_000; // 30 s — public catalog, session list
export const REALTIME_STALE_TIME = 5_000; // 5 s — live player state, slot progression
export const STATIC_STALE_TIME = Infinity; // legal pages, env-config data
export const SESSION_STALE_TIME = 60_000; // 60 s — session-level state polled less aggressively
export const DEFAULT_GC_TIME = 300_000; // 5 min (300 s) — default garbage collection window

export function makeQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: DEFAULT_STALE_TIME, // 30 s
        gcTime: DEFAULT_GC_TIME, // 300 s
        retry: 1,
      },
    },
  });
}
