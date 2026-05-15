# Story 20.8: Centralise QueryClient Configuration

## Story

**As a** developer,
**I want** `QueryClient` instantiation and default `staleTime` / `gcTime` values defined in a single file,
**So that** caching behaviour is consistent across all features and changing a global default is a one-line edit.

## Status

todo

## Acceptance Criteria

**AC1:** A grep audit of the entire `frontend/` directory (excluding `node_modules/`, `.next/`, `dist/`, `coverage/`) identifies all `new QueryClient(...)` call sites and all raw numeric `staleTime` / `gcTime` literals in `useQuery` / `useInfiniteQuery` calls. Searching `frontend/` rather than `frontend/src` ensures Storybook (`.storybook/`) and other config directories outside `src/` are covered.

**AC2:** `src/lib/query-client.ts` is created and exports:
```ts
export const DEFAULT_STALE_TIME = 30_000;    // 30 s — public catalog, session list
export const REALTIME_STALE_TIME = 5_000;    // 5 s — live player state, slot progression
export const STATIC_STALE_TIME = Infinity;   // legal pages, env-config data
export const SESSION_STALE_TIME = 60_000;    // 60 s — session-level state polled less aggressively
export const DEFAULT_GC_TIME = 300_000;  // 5 min (300 s) — default garbage collection window

export function makeQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: DEFAULT_STALE_TIME,  // 30 s
        gcTime: DEFAULT_GC_TIME,        // 300 s
        retry: 1,
      },
    },
  });
}
```

**AC3:** All `new QueryClient(...)` call sites (providers, test helpers, Storybook setup if present) are replaced by `makeQueryClient()`.

**AC4:** All raw numeric `staleTime` **and** `gcTime` values in `useQuery` / `useInfiniteQuery` calls are replaced by named constants from `query-client.ts`. Any value that does not match an existing constant gets a new named constant added to `query-client.ts` with a comment explaining the domain reason.

**AC5:** `pnpm typecheck`, `pnpm lint`, and `pnpm build` remain clean (0 errors, 0 warnings).

## Tasks / Subtasks

- [ ] Task 1: Create story file (this file)
- [ ] Task 2: Run grep audit across all of `frontend/` **before creating `query-client.ts`** (run from repo root):
  ```bash
  rg 'new QueryClient' frontend --glob '!**/node_modules/**' --glob '!**/.next/**' --glob '!**/dist/**' --glob '!**/coverage/**'
  rg 'staleTime:' frontend --glob '!**/node_modules/**' --glob '!**/.next/**' --glob '!**/dist/**' --glob '!**/coverage/**'
  rg 'gcTime:' frontend --glob '!**/node_modules/**' --glob '!**/.next/**' --glob '!**/dist/**' --glob '!**/coverage/**'
  ```
  - [ ] List each occurrence: file, line, current value (includes `.storybook/` if present)
  - [ ] Note: if the audit is re-run after Task 3, add `--glob '!**/src/lib/query-client.ts'` to exclude the new constant definitions from results
- [ ] Task 3: Create `src/lib/query-client.ts`
  - [ ] 3a: Define the five standard constants (`DEFAULT_STALE_TIME`, `REALTIME_STALE_TIME`, `STATIC_STALE_TIME`, `SESSION_STALE_TIME`, `DEFAULT_GC_TIME`)
  - [ ] 3b: Implement `makeQueryClient()` with default options using `DEFAULT_STALE_TIME` and `DEFAULT_GC_TIME`
- [ ] Task 4: Replace all `new QueryClient(...)` with `makeQueryClient()`
  - [ ] `src/lib/query-provider.tsx` (or equivalent provider file)
  - [ ] Any other call sites found in audit
- [ ] Task 5: Replace all raw `staleTime` and `gcTime` numeric literals with named constants
  - [ ] `staleTime` mapping: 0–5 000 ms → `REALTIME_STALE_TIME`; ~30 000 ms → `DEFAULT_STALE_TIME`; ~60 000 ms → `SESSION_STALE_TIME`; `Infinity` → `STATIC_STALE_TIME`
  - [ ] `gcTime` mapping: `5 * 60_000` or `300_000` → `DEFAULT_GC_TIME`
  - [ ] Add new named constants for any values that don't map to an existing tier
- [ ] Task 6: Run `pnpm typecheck`, `pnpm lint`, `pnpm build` — all clean

## Dev Notes

### Standard constants

| Constant | Value | Used for |
|---|---|---|
| `REALTIME_STALE_TIME` | 5 000 ms | Player state, slot progression, session status — bridge updates every few seconds |
| `DEFAULT_STALE_TIME` | 30 000 ms | Public event catalog, game library, player profile — changes rarely during a session |
| `SESSION_STALE_TIME` | 60 000 ms | Session-level state polled less aggressively than realtime slot data |
| `STATIC_STALE_TIME` | `Infinity` | Legal pages, env config — effectively static between deployments |
| `DEFAULT_GC_TIME` | 300 000 ms | Default garbage collection window (5 min) for all query data |

If a feature needs a value outside these tiers, add a named constant rather than using a magic number:
```ts
export const HINTS_STALE_TIME = 10_000; // 10 s — hint state updates on each LocationScouts response
```

### STATIC_STALE_TIME and JSON serialisation

`JSON.stringify(Infinity)` produces `"null"`, not `"Infinity"`. This is normally harmless because TanStack Query's `dehydrate()` does **not** include `staleTime` in the serialised output — it is a runtime QueryClient configuration, not query state. Dehydrated payloads only carry data, status, `dataUpdatedAt`, etc., so passing a dehydrated state from a server component to the client via props is safe even when `STATIC_STALE_TIME` is in use.

Two things to avoid:
- Do **not** use `STATIC_STALE_TIME` as a value for `gcTime` — TanStack Query v5 may include `gcTime` in dehydrated state in some configurations, and `Infinity` would become `null` after serialisation.
- Do **not** put `STATIC_STALE_TIME` (or `Infinity`) into any object that is JSON-serialised and sent over the wire or stored.

If a query genuinely needs indefinite caching on both client and server, use `staleTime: STATIC_STALE_TIME` (safe) combined with a finite `gcTime` (also safe).

### QueryClient in server components

Next.js App Router server components that use `dehydrate`/`HydrationBoundary` for prefetching create a **new** `QueryClient` per request (to avoid cross-request data sharing). These must also use `makeQueryClient()`:
```ts
// app/(public)/joueurs/[playerSlug]/page.tsx
import { makeQueryClient } from "@/lib/query-client";

export default async function PlayerProfilePage({ params }) {
  const queryClient = makeQueryClient();
  await queryClient.prefetchQuery({ queryKey: ["player", slug], queryFn: () => fetchPlayerProfile(slug) });
  // ...
}
```

### Default options vs per-call staleTime

`makeQueryClient()` sets a `defaultOptions.queries.staleTime`. Individual `useQuery` calls that override `staleTime` must use a named constant — the default is a floor, not a ceiling.

### Relationship to Story 20.7

Story 20.7 test helpers that create a `QueryClient` for testing purposes must also use `makeQueryClient()`. If Story 20.7 is implemented first with `new QueryClient()`, update those calls here.

## File List

- `frontend/src/lib/query-client.ts` — new: `makeQueryClient()` + named staleTime and gcTime constants
- `frontend/src/lib/query-provider.tsx` — updated: use `makeQueryClient()`
- Any file found by the Task 2 audit across `frontend/` (including `.storybook/` if present) with `new QueryClient(...)`, raw `staleTime`, or raw `gcTime` literals
- `_bmad-output/implementation-artifacts/20-8-frontend-centralise-queryclient.md` — this file

## Change Log

| Date       | Change         |
|------------|----------------|
| 2026-05-15 | Story created  |
