# Story 33.7: React 19 / Next 15 Best-Practices Pass (frontend/)

Status: done

## Story

As a maintainer of the frontend,
I want every concrete violation of `frontend/AGENTS.md` enumerated and either fixed or explicitly accepted with rationale,
so that the frontend's conformance floor rises with zero behaviour change and the systemic residue is recorded as sized follow-up work instead of silently ignored.

## Acceptance Criteria

1. **AC1 - Audit checklist committed first.** A worklist (`_bmad-output/implementation-artifacts/33-7-audit-worklist.md`) enumerating every concrete finding with a file reference is committed before any code change; it records fix-vs-accept dispositions and IS the story scope.
2. **AC2 - Every checklist item resolved or accepted.** At minimum RESOLVED: the ~13 real array-index list keys, the 1 default export in `features/`, the removable `"use client"` (SeatCounter), the dead `legal-placeholder.tsx`, the 4 clear render-impurity sites (AC-HK3), the 8 direct `res.json()`/error-body `as` casts, justification comments on every bare `react-hooks/*` eslint-disable, the bounded simple fetch-in-effect conversions (F8 list), and the trivial static-style/Discord-token CSS fixes. Systemic residuals (complex fetch-in-effect pages, apiFetch-in-components, SSE casts, manual `as Record` parsers, decorative palettes) are ACCEPTED with recorded rationale + follow-up candidates.
3. **AC3 - All gates green, zero behaviour change.** `pnpm gates` passes (typecheck 0, lint 0/0, jest green, build clean); no visual change (token extraction keeps identical color values); `composer gates` unaffected (zero api changes).

## Tasks / Subtasks

- [x] Task 1: Commit the audit worklist (AC: 1) → `c2d8ebb`.
- [x] Task 2: Mechanical fixes (AC: 2) - keys executed with per-site verdicts (7 fixed, 3 kept-with-justification in controlled editors, 5 reclassified as stable element-value skeletons - worklist F1 updated); `AdminNewGamePage` named export + importer; `"use client"` dropped from seat-counter; `legal-placeholder.tsx` deleted.
- [x] Task 3: Purity + type-safety fixes (AC: 2) - 4 AC-HK3 sites → lazy/mount-stable `useState` initializers; 10 cast sites converted to `unknown` + guards reusing `lib/type-guards.ts` helpers (guard failures route to existing error paths); ~16 bare `react-hooks/*` disables now carry factual justifications.
- [x] Task 4: Bounded fetch-in-effect conversions (AC: 2) - all 4 converted, none entangled: `retry: false` everywhere (matches old single-shot semantics), `staleTime: DEFAULT_STALE_TIME`, mutation flow in game-request-section ported via `setQueryData` cache patches (invalidation would have added refetches the old code never made), join-page preview uses a discriminated api result to preserve the 404/server/network error distinctions. New api modules: `admin/admin-slots-api.ts`, `personal-runs/personal-runs-api.ts`.
- [x] Task 5: CSS trivia (AC: 2) - 3 static styles → Tailwind; Discord color → `@theme` tokens (`--color-discord`/`--color-discord-hover`, identical hex); info-tooltip reclassified accepted (imperatively mutated style = dynamic case).
- [x] Task 6: Gates + PR (AC: 3) - `pnpm gates` green first try (typecheck 0, lint 0/0, jest 172/172, build clean); PR opened; merge on green CI authorized in-session.

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

Claude Fable 5 (claude-fable-5) - orchestrator + 3 audit agents + 3 implementation agents on disjoint file sets

### Debug Log References

- Audits: hooks/effects (20 effects/16 files systemic, HK2 7 sites, HK3 4 clear, HK5/API5/ST3 clean), types/env/API (any 0, staleTime 32/32, raw fetch 15 sites, casts ~30 network), keys/components (13 candidate keys, 1 default export, SeatCounter, dead legal-placeholder).
- Execution reclassifications recorded in worklist F1/F9: 5 "index keys" were element-value skeleton maps; 3 controlled editors keep index keys with in-code justification; info-tooltip style is imperative-dynamic.
- Gates: `pnpm gates` exit 0 first try; explicit re-verify typecheck 0 / lint 0 / jest 172/172.

### Completion Notes List

- All fix items (F1-F9) executed or reclassified with recorded reasons; 13 acceptances stand; 2 follow-up story candidates recorded ("TanStack Query migration" ~14 pages + apiFetch relocation; "typed SSE layer" killing ~22 casts).
- Zero behaviour change: converted queries replicate old retry/loading/error semantics exactly (retry: false, cache patches instead of invalidation, discriminated invite-preview result); guard failures route to pre-existing error paths; Discord token keeps identical hex.
- Zero api/ changes; `composer gates` untouched by construction.

### File List

- Worklist + this story file
- Mechanical: `features/admin/admin-new-game-page.tsx` + `app/(admin)/admin/jeux/nouveau/page.tsx` (named export), `features/events/seat-counter.tsx` (server component), deleted `components/legal-placeholder.tsx`
- Keys: `features/games/install-steps-view.tsx`, `features/events/game-selection-gate.tsx`, `features/personal-runs/personal-run-game-selection-page.tsx`, `features/admin/admin-registration-detail.tsx`, `features/admin/admin-guided-game-creation.tsx`, `features/admin/admin-catalogue-sync-page.tsx`, `features/community/community-profile-customization-form.tsx` (+ justified keeps in `install-steps-editor.tsx`, `admin-achievements-dashboard.tsx`, `yaml-option-editor.tsx`)
- Purity/casts/justifs: `components/date-time-picker.tsx`, `features/admin/admin-membership-dashboard.tsx`, `features/admin/account-moderation-controls.tsx`, `features/auth/account-registrations.tsx`, `features/auth/signup-form.tsx`, `features/events/session-connection-gate.tsx`, `features/events/event-registration-cta.tsx`, `features/personal-runs/personal-run-slot-detail-page.tsx`, `features/admin/admin-slot-reachability-page.tsx`, `features/admin/admin-session-page.tsx`, `features/streaming/twitch-mini-player.tsx`, `features/games/games-catalog.tsx`, `features/games/use-steam-coupling.ts`, `features/weekly-runs/weekly-run-slot-page.tsx`
- Conversions: `features/overlay/overlay-links-panel.tsx`, `features/admin/admin-slot-switcher.tsx`, `features/games/game-request-section.tsx`, `features/personal-runs/join-page.tsx`, new `features/admin/admin-slots-api.ts`, new `features/personal-runs/personal-runs-api.ts`
- CSS: `features/streaming/live-twitch-badge.tsx`, `features/reachability/goal-celebration.tsx`, `features/auth/account-profile.tsx`, `features/auth/discord-button.tsx`, `app/globals.css` (Discord tokens)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created from three parallel frontend audits. 9 axes verified clean; finite fix list (F1-F10) + 13 acceptances; systemic fetch-in-effect/apiFetch/SSE-cast residuals bounded into recorded follow-up candidates instead of ballooning this pass. Status: ready-for-dev. |
| 2026-07-05 | Story executed via 3 parallel implementation agents on disjoint file sets + orchestrator: keys (7 fixed / 3 justified keeps / 5 reclassified), purity + casts + disable justifications, 4 TanStack Query conversions with exact semantics preservation, CSS tokens. Gates green first try (typecheck 0, lint 0/0, jest 172/172, build clean). Status → ready-for-review. |
