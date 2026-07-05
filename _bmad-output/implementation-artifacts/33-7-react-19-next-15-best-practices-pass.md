# Story 33.7: React 19 / Next 15 Best-Practices Pass (frontend/)

Status: ready-for-dev

## Story

As a maintainer of the frontend,
I want every concrete violation of `frontend/AGENTS.md` enumerated and either fixed or explicitly accepted with rationale,
so that the frontend's conformance floor rises with zero behaviour change and the systemic residue is recorded as sized follow-up work instead of silently ignored.

## Acceptance Criteria

1. **AC1 - Audit checklist committed first.** A worklist (`_bmad-output/implementation-artifacts/33-7-audit-worklist.md`) enumerating every concrete finding with a file reference is committed before any code change; it records fix-vs-accept dispositions and IS the story scope.
2. **AC2 - Every checklist item resolved or accepted.** At minimum RESOLVED: the ~13 real array-index list keys, the 1 default export in `features/`, the removable `"use client"` (SeatCounter), the dead `legal-placeholder.tsx`, the 4 clear render-impurity sites (AC-HK3), the 8 direct `res.json()`/error-body `as` casts, justification comments on every bare `react-hooks/*` eslint-disable, the bounded simple fetch-in-effect conversions (F8 list), and the trivial static-style/Discord-token CSS fixes. Systemic residuals (complex fetch-in-effect pages, apiFetch-in-components, SSE casts, manual `as Record` parsers, decorative palettes) are ACCEPTED with recorded rationale + follow-up candidates.
3. **AC3 - All gates green, zero behaviour change.** `pnpm gates` passes (typecheck 0, lint 0/0, jest green, build clean); no visual change (token extraction keeps identical color values); `composer gates` unaffected (zero api changes).

## Tasks / Subtasks

- [ ] Task 1: Commit the audit worklist (AC: 1)
- [ ] Task 2: Mechanical fixes (AC: 2) - index keys → stable keys (13 files); named export for `admin-new-game-page` + importer; drop `"use client"` from `seat-counter.tsx`; delete `components/legal-placeholder.tsx`
- [ ] Task 3: Purity + type-safety fixes (AC: 2) - 4 AC-HK3 sites (date-time-picker, admin-membership-dashboard, account-moderation-controls); 8 `as`-cast sites → `as unknown` + narrow validation; justification comments on ~16 bare `react-hooks/*` disables
- [ ] Task 4: Bounded fetch-in-effect conversions (AC: 2) - `overlay-links-panel`, `admin-slot-switcher`, `game-request-section`, `join-page` → TanStack Query (staleTime explicit); fallback clause: any of the 4 that turns out entangled joins the accepted residual with rationale
- [ ] Task 5: CSS trivia (AC: 2) - 4 static inline styles → Tailwind; Discord `#5865F2`/`#4752C4` → shared token if the design-token file makes it a small change, else accept
- [ ] Task 6: Gates + PR (AC: 3) - `pnpm gates`; PR to `develop` from `feature/epic-33-story-7-react-next-best-practices`; merge on green CI (authorized by Jean in-session)

## Dev Notes

### Audit summary (3 parallel audits, 2026-07-05, develop = d19d8aa, 353 TS/TSX files, 15 features)

**Verified clean (9 axes):** `any` usage 0; `useQuery` staleTime 32/32 explicit; `initialDataUpdatedAt` 1/1 prop-sourced (exemplary in leaderboard-client); api functions never throw (AC-API2); type-guard colocation OK; `interface` 1/1 legitimate (declaration merging); `process.env` 0 in src (eslint-enforced, config/test files sanctioned); `params` awaited in all ~30 dynamic pages, no route.ts; `notFound()` discipline 8/8; prop types explicit everywhere; AC-ST3 useRef discipline clean.

**Findings and dispositions: see the worklist (F1-F10 fixes, C1-C13 accepted).** Key systemic decisions:
- fetch-in-effect (~18 files): the 4 simple ones are converted (Task 4); the complex admin/slot pages (state machines + polling + SSE) and the auth bootstrap are accepted with a recorded follow-up story candidate ("TanStack Query migration": AC-NX1 + AC-API1 + AC-ST2 residual, ~14 files).
- `apiFetch` called directly in 86 component files: accepted - `apiFetch` is the sanctioned typed transport wrapper; strict AC-API1 relocation is a mega-refactor for zero behaviour gain; joins the follow-up story.
- SSE `JSON.parse(...) as DomainType` (~22 sites) + generic `as T` in `use-sse.ts`/`use-overlay-stream.ts`: accepted - single-source Mercure hub, repeated legacy pattern; follow-up candidate "typed SSE layer with guards".

### Previous story intelligence (33.5/33.6)

- Windows: sed/bash backslash rewrites no-op silently - use PowerShell literal `.Replace()` or the Edit tool.
- Audit-first discipline: verify seeded findings before editing; content-level scans beat use-line scans.
- Frontend env vars for gates: `NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN` needed by test/build (in `.env.local`).
- Repo conventions: merge commits, PR to develop, story stays ready-for-review after merge, no em-dashes.

### Project Structure Notes

- Diff footprint: `frontend/src/**` only (~35 files), worklist + story. Zero api/ changes, zero visual change.
- ESLint already enforces AC-ENV1 and exhaustive-deps; jest suite (30 suites/172 tests) is the behaviour contract for converted components - check each converted component for existing tests; add a smoke test only where a conversion has none.

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md` (1b9e869) - story 33.7 AC.
- Standards: `frontend/AGENTS.md` (AC-TS*, AC-ENV*, AC-NX*, AC-CO*, AC-HK*, AC-API*, AC-KEY*, AC-ST*, AC-CSS*).
- Audit reports: three parallel scans recorded in the worklist (hooks/effects; types/env/API; keys/components/dead code).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created from three parallel frontend audits. 9 axes verified clean; finite fix list (F1-F10) + 13 acceptances; systemic fetch-in-effect/apiFetch/SSE-cast residuals bounded into recorded follow-up candidates instead of ballooning this pass. Status: ready-for-dev. |
