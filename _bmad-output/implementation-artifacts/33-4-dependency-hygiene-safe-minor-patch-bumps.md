# Story 33.4: Dependency Hygiene - Safe (Minor/Patch) Bumps

Status: ready-for-review

## Story

As a maintainer of the api and frontend dependency trees,
I want every open non-major Dependabot PR merged behind green gates (or closed with a recorded reason),
so that the sweeps of 33.5-33.7 run against current minor/patch dependencies and the known npm advisories (Next.js) are cleared from the audit backlog.

## Acceptance Criteria

1. **AC1 - The npm minor/patch group lands.** Dependabot **PR #281** ("Bump the npm-minor-patch group across 1 directory with 21 updates", created 2026-07-02, base `develop`) is merged behind green gates. Notable content: `next` 16.2.4→16.2.10 + `eslint-config-next` (clears the Next.js advisories that keep `pnpm audit` warn-only), `react`/`react-dom` 19.2.4→19.2.7, `@tanstack/react-query` 5.100.1→5.101.2, the eight `@tiptap/*` 3.24.0→3.27.1, `radix-ui`, `lucide-react`, `shadcn`, `tailwind-merge`, `react-icons`, `@next/bundle-analyzer`, `@types/react`, `@tailwindcss/postcss`, `tailwindcss`. Its own CI already ran the full frontend gate set (Frontend checks SUCCESS - the PR touches `frontend/**`).
2. **AC2 - @types/node resolved correctly (close #41, align to ^22).** The epic lists `@types/node` in 33.4's scope, but **PR #41** (20.19.39→25.9.2) must NOT be merged: the frontend runtime is Node 22 everywhere (`frontend/Dockerfile` `node:22-alpine`, CI `setup-node` `node-version: '22'`), and `@types/node` should match the runtime major. Resolution: close #41 with that reason (and `@dependabot ignore` the 25.x major so it stops re-proposing), and bump `frontend/package.json` `@types/node` from `^20` to `^22` manually (types-only alignment to the runtime that is already live - no runtime change). Fallback: if `pnpm typecheck` surfaces non-trivial breakage from the 20→22 types, revert the manual bump, keep `^20`, and record the deferral (ride along with the Node 26 image migration in 33.9).
3. **AC3 - Majors stay deferred, nothing silently dropped.** PRs #42 (typescript 6), #39 (php 8.5 image), #37 (node 26 image) remain open and untouched, explicitly deferred to 33.9. The api side has zero open composer Dependabot PRs (config exists in `.github/dependabot.yml`, weekly, `composer-minor-patch` group) - recorded as "nothing to merge", not skipped.
4. **AC4 - All gates green.** `pnpm gates` passes locally on the merged result (post-#281 + `@types/node` ^22); the `develop` merge-push CI runs are green. `composer gates` is unaffected (no api changes) - regression evidence via the merge-push Backend check being green/skipped as appropriate.
5. **AC5 - Audit backlog status recorded.** After the merges, run `pnpm audit --audit-level high` and record the result in the Dev Agent Record: if it is now clean, note that story 0.7 AC3's "flip audit to hard gate" becomes actionable (follow-up story, NOT done here - it is a workflow change out of this story's scope).

## Tasks / Subtasks

- [x] Task 1: Review and merge PR #281 (AC: 1)
  - [x] 1.1 Confirm the PR is current vs `develop`. → mergeStateStatus CLEAN, no rebase needed.
  - [x] 1.2 Verify its checks are green, then merge. → All 6 checks green (Frontend checks 2m43s full gate set); merged as `39b1bd3` after Jean's in-session go; Dependabot branch deleted.
  - [x] 1.3 Watch the `develop` merge-push runs. → All three runs SUCCESS on `39b1bd3` (Backend Quality 28704779760, Docker Publish 28704779767, Frontend Quality 28704779756).
- [x] Task 2: Resolve @types/node (AC: 2)
  - [x] 2.1 Close PR #41 with the runtime-mismatch reason; `@dependabot ignore this major version`. → Reason commented, ignore command posted, Dependabot closed #41.
  - [x] 2.2 Set `"@types/node": "^22"` in `frontend/package.json`, `pnpm install`. → Resolved to 22.20.0; lockfile updated.
  - [x] 2.3 Run `pnpm gates`. → Green first try, no fallback needed: typecheck 0, lint 0, jest 30 suites / 172 tests, `next build` clean on Next 16.2.10.
- [x] Task 3: Record majors deferral and api status (AC: 3)
  - [x] 3.1 Verify #42, #39, #37 still open and untouched. → Confirmed open (typescript 6, php 8.5 image, node 26 image), deferred to 33.9.
  - [x] 3.2 Confirm zero open composer Dependabot PRs. → Confirmed: api has nothing to merge this cycle.
- [x] Task 4: Gates and audit status (AC: 4, 5)
  - [x] 4.1 `pnpm gates` green locally on the final state. → Green (see 2.3).
  - [x] 4.2 `pnpm audit --audit-level high`. → NOT clean: 1 high remains - `ws` >=8.0.0 <8.21.0 via `jest-environment-jsdom > jsdom > ws` (GHSA-96hv-2xvq-fx4p, dev-only test path), plus 2 moderate. The Next.js highs ARE cleared by #281. The 0.7 AC3 audit-gate flip is therefore NOT yet actionable; the `ws` fix will arrive via jsdom's dependency chain (future Dependabot minor/patch group).
  - [x] 4.3 Dev Agent Record complete; story file lands via PR to develop with the @types/node change. → Done.

## Dev Notes

### Execution shape

- **Two vehicles:** PR #281 rides its own Dependabot branch (merge it, do not replicate); the `@types/node` ^22 alignment + this story file ride a `feature/epic-33-story-4-*` branch created AFTER #281 is merged (so the lockfile edit builds on the merged state, avoiding a pnpm-lock conflict).
- **Why #41 is closed, not merged:** `@types/node` majors track Node.js majors. Runtime is Node 22 (Dockerfile + CI); types at 25 would type-check against APIs the runtime does not have. `^22` is the correct hygiene target; 25/26 types belong with the Node 26 image migration (33.9, PR #37).
- **Epic guardrails:** minor/patch only here; any bump needing code changes is split out and noted (epic AC2); majors are migrations (33.9). No behaviour change expected - these are lockfile/manifest updates verified by the full frontend gate set.
- **Sequencing rationale (epic):** 33.4 lands before the sweeps (33.5-33.7) so they audit current deps.

### Current state (at story creation, develop = 7ac0a17)

- Open Dependabot PRs: #281 (npm minor/patch group - THIS story), #41 (@types/node 25 - close per AC2), #42/#39/#37 (majors - 33.9). No composer PRs.
- PR #281 CI: Frontend checks SUCCESS (full gate set: lint, typecheck, jest, build, bundle analysis), aggregate checks green; created 2026-07-02, Dependabot-rebased 2026-07-04.
- `frontend/package.json`: `"@types/node": "^20"`, `"typescript": "^5"`; Dockerfile `node:22-alpine`; CI `setup-node` 22, pnpm 10.
- `pnpm audit --audit-level high` is warn-only in CI (`continue-on-error`), backlog tracked by story 0.7 AC3; the known high advisories were Next.js < 16.2.5, which #281's `next` 16.2.10 clears.

### Previous story intelligence (33.3)

- Dependabot-PR-as-vehicle pattern worked cleanly: pre-merge review (diff vs worklist, CI green, risky-bump spot-checks), merge with `gh pr merge N --merge --delete-branch`, post-merge run watch (`gh run watch <id> --exit-status`), annotations check via `gh api .../check-runs/<job>/annotations`.
- Merge-push to develop triggers Docker Publish too (its `paths` include `frontend/**`) - expect three runs, one with the heavy job skipped.
- Story files stay `ready-for-review` after merge; repo merges = merge commits; PRs target `develop`.

### Project Structure Notes

- Diff footprint: `frontend/package.json` + `frontend/pnpm-lock.yaml` (via #281 and the ^22 alignment), plus this story file. Zero `api/`, zero workflow changes.
- Frontend rules (frontend/AGENTS.md) are unaffected - no source code changes expected; if a bump forces one, that is the epic's "split out and note" clause.

### References

- Epic definition: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md` (branch `feature/epic-33-cleanup-and-standards`, commit 1b9e869) - story 33.4 AC, decisions "A major dependency bump is a migration, not hygiene", sequencing note 3.
- PR #281: https://github.com/ArchiLAN-dev/archilan.fr/pull/281 (npm minor/patch group, 21 updates)
- PR #41: https://github.com/ArchiLAN-dev/archilan.fr/pull/41 (@types/node 25 - to close)
- Story 33.3 scope note (github_actions group already landed): `_bmad-output/implementation-artifacts/33-3-github-actions-modernisation.md`
- Audit warn-only context: `.github/workflows/frontend.yml` (pnpm audit step comment, story 0.7 AC3).

## Dev Agent Record

### Agent Model Used

Claude Fable 5 (claude-fable-5)

### Debug Log References

- PR #281: mergeStateStatus CLEAN, 6/6 checks green → merged `39b1bd3`; develop merge-push runs 28704779760 / 28704779767 / 28704779756 all SUCCESS.
- PR #41: reason + `@dependabot ignore this major version` → closed by Dependabot.
- Local: `pnpm install` (@types/node 22.20.0), `pnpm gates` → exit 0; `pnpm audit --audit-level high` → exit 1 (ws high, dev-only).

### Completion Notes List

- **Per-PR outcome table:**
  | PR | Content | Outcome |
  |---|---|---|
  | #281 | npm minor/patch group, 21 updates (next 16.2.10, react 19.2.7, tiptap 3.27.1, tanstack-query 5.101.2, radix/lucide/shadcn/tailwind etc.) | Merged (`39b1bd3`), CI + post-merge runs green |
  | #41 | @types/node 20.19.39 → 25.9.2 | Closed with reason (runtime is Node 22); 25.x majored-ignored; manual alignment `^20` → `^22` (22.20.0) done in this story, gates green |
  | #42 | typescript 5.9.3 → 6.0.3 | Untouched, deferred to 33.9 |
  | #39 | php 8.4 → 8.5-cli-alpine image | Untouched, deferred to 33.9 |
  | #37 | node 22 → 26-alpine image | Untouched, deferred to 33.9 |
  | composer | - | No open PRs this cycle (config active, weekly, composer-minor-patch group) |
- **AC5 audit status:** not clean - 1 high (`ws` < 8.21.0 via jest-environment-jsdom > jsdom, GHSA-96hv-2xvq-fx4p, dev/test-only) + 2 moderate. Next.js advisories cleared. Story 0.7 AC3's hard-gate flip stays blocked on the `ws` chain; no action here (transitive dev dependency, fix rides jsdom updates).
- No source code changes were needed anywhere (epic's "split out and note" clause unused); observable behaviour unchanged, verified by the full frontend gate set locally and in CI.
- Merges green-lit in-session by Jean ("go").

### File List

- `frontend/package.json` (modified - #281 bumps via merge + manual @types/node ^22)
- `frontend/pnpm-lock.yaml` (modified - same)
- `_bmad-output/implementation-artifacts/33-4-dependency-hygiene-safe-minor-patch-bumps.md` (this story file)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-04 | Story executed: PR #281 (21 npm minor/patch bumps) merged as `39b1bd3` with green CI and post-merge runs; PR #41 closed with rationale and @types/node manually aligned to ^22 (gates green); majors #42/#39/#37 confirmed deferred to 33.9; api composer queue empty; audit residual recorded (ws high, dev-only). Status → ready-for-review. |
