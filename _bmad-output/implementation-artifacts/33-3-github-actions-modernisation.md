# Story 33.3: GitHub Actions Modernisation

Status: ready-for-review

## Story

As a maintainer of the CI pipelines,
I want every GitHub Action used by our workflows bumped to a Node 24-capable major (or explicitly pinned with a documented upstream blocker),
so that CI keeps running after GitHub removes Node 20 from the runners on 2026-09-16, and the recurring deprecation warnings disappear from every run.

## Acceptance Criteria

1. **AC1 - Every deprecated action bumped (audit worklist = the 11 updates below).** All actions used by the three workflows (`.github/workflows/backend.yml`, `frontend.yml`, `docker-publish.yml`) run on a Node 24-capable major. The concrete vehicle is **Dependabot PR #277** ("bump the actions group across 1 directory with 11 updates", opened 2026-06-30, base `develop`), which is exactly this story's scope:

   | Action | From | To | Workflows |
   |---|---|---|---|
   | `actions/checkout` | v4 | v7 | all three |
   | `actions/cache` | v4 | v6 | backend |
   | `actions/upload-artifact` | v4 | v7 | backend, frontend |
   | `actions/setup-node` | v4 | v6 | frontend |
   | `pnpm/action-setup` | v4 | v6 | frontend |
   | `dorny/paths-filter` | v3 | v4 | backend, frontend |
   | `docker/setup-buildx-action` | v3 | v4 | docker-publish |
   | `docker/login-action` | v3 | v4 | docker-publish |
   | `docker/metadata-action` | v5 | v6 | docker-publish |
   | `docker/build-push-action` | v6 | v7 | docker-publish |
   | `aquasecurity/trivy-action` | 0.35.0 | v0.36.0 | docker-publish |

   Each bump is reviewed (release notes / breaking-changes check) before merge; any bump that turns out to be unsafe is dropped from the PR (via `@dependabot ignore`) and documented as a pinned blocker rather than silently stalled. `shivammathur/setup-php@v2` is already current (rolling major) - no change needed.
2. **AC2 - Post-merge runs green with no Node 20 deprecation warning.** After merge to `develop`: the Backend Quality, Frontend Quality **and Docker Publish** workflow runs triggered by the merge push all succeed, and their run summaries contain **zero** "Node.js 20 actions are deprecated" (or equivalent) annotations. Any residual warning is documented as upstream-blocked in this story's Dev Agent Record. Note: Docker Publish is NOT exercised by the PR's own CI (it only runs on push to `develop`/`main`), so this verification is mandatory post-merge, not optional.
3. **AC3 - Branch-protection gating still works.** The `changes` jobs (dorny/paths-filter v4) still emit correct outputs: the merge-push run shows the path filters detecting changes correctly, and the aggregate `Backend` / `Frontend` required checks report green. (These two checks are what branch protection consumes; a silent paths-filter regression would break PR gating repo-wide.)
4. **AC4 - Story 33.4 scope note recorded.** The Dev Agent Record states that the `github_actions` Dependabot group is landed by this story, so story 33.4 (dependency hygiene) no longer includes it - 33.4's remaining scope is the npm minor/patch group (PR #281), with majors (PRs #37, #39, #41, #42) still deferred to 33.9.
5. **AC5 - No repo code touched.** The diff is confined to `.github/workflows/*.yml` (via the Dependabot PR). `composer gates` / `pnpm gates` are unaffected by definition; no local gate run is required beyond confirming the working tree stays clean.

## Tasks / Subtasks

- [x] Task 1: Pre-merge review of Dependabot PR #277 (AC: 1)
  - [x] 1.1 Confirm the PR diff still matches the 11-bump table above (`gh pr diff 277`) and that no workflow file changed on `develop` since the PR's base (as of story creation: none - PRs #282/#283 touched no workflow file, so no rebase conflict expected; if GitHub reports the PR out of date, comment `@dependabot rebase` and wait for CI to re-run green). → Diff re-verified against the table; last workflow-touching commit on develop was 2278675 (PR #276), predating #277; mergeStateStatus CLEAN, no rebase needed.
  - [x] 1.2 Verify the PR's own CI is green (as of story creation: all 6 checks SUCCESS - Backend, Backend checks, Frontend, Frontend checks, both change-detection jobs - meaning both quality workflows already executed successfully WITH the new action versions, since the diff touches the workflow files themselves and the path filters include them). → Re-confirmed: all 6 checks SUCCESS.
  - [x] 1.3 Spot-check the risky bumps (all pre-verified at story creation, re-confirm nothing changed): → All three re-confirmed: trivy tag `v0.36.0` exists, paths-filter v4.0.2 latest with green change-detection jobs on the PR, `grep download-artifact .github/` returns 0 matches.
    - `aquasecurity/trivy-action@v0.36.0`: tag format changes from `0.35.0` to `v0.36.0` - tag existence verified via GitHub API (`refs/tags/v0.36.0` exists). An unresolvable `uses:` ref fails the job at setup regardless of the step's `continue-on-error`, which is why this was checked.
    - `dorny/paths-filter@v4`: third-party action that branch protection indirectly depends on; v4.0.2 is the latest release; the PR's green change-detection jobs already prove the outputs contract (`steps.filter.outputs.api|frontend`) is intact.
    - `actions/upload-artifact@v7`: no documented breaking changes v4→v7 for our usage (upload-only; the repo uses no `download-artifact` anywhere, so the paired v8 requirement does not apply). New `archive:` input defaults to `true` (backwards compatible).
- [x] Task 2: Merge PR #277 into develop (AC: 1) [human: merge approval per epic role note]
  - [x] 2.1 Merge with a merge commit (repo convention): `gh pr merge 277 --merge --delete-branch`. → Merged as `eac496a` (2026-07-04) after Jean's explicit in-session go; Dependabot branch deleted.
- [x] Task 3: Post-merge verification on develop (AC: 2, 3)
  - [x] 3.1 Watch the three workflow runs triggered by the merge push to `develop` (Backend Quality, Frontend Quality, Docker Publish - the latter fires because `.github/workflows/docker-publish.yml` is in its own `paths` filter). All must conclude SUCCESS. → All three SUCCESS on `eac496a`: Backend Quality 28703999075, Frontend Quality 28703999059, Docker Publish 28703999100.
  - [x] 3.2 Inspect each run's annotations: zero Node 20 deprecation warnings remain. → Zero deprecation annotations across all three runs. The only annotations are `Process completed with exit code 1` on the by-design warn-only `continue-on-error` steps (Trivy CVE scans on Docker Publish, pnpm audit on Frontend) - pre-existing, story 0.7 AC3 backlog, unrelated to Node 20. No upstream-blocked residual: all 11 bumps landed.
  - [x] 3.3 Confirm gating integrity (AC3): → paths-filter v4 outputs correct (the workflow-file change matched both filters, so both heavy `checks` jobs ran full); aggregate `Backend` / `Frontend` checks green.
  - [x] 3.4 Docker Publish specifics: → All three Trivy steps (v0.36.0) resolved and executed (their warn-only annotations prove execution); all three image jobs (API web, API worker, Frontend) succeeded including the GHCR push steps with `develop` tags.
- [x] Task 4: Record outcomes (AC: 4, 5)
  - [x] 4.1 Dev Agent Record: per-bump outcome table (merged as-is / dropped+pinned with reason), post-merge run IDs and their annotation status, and the 33.4 scope note. → Recorded below.
  - [x] 4.2 Confirm the story's diff footprint: workflow files only (owned by the Dependabot PR), plus this story file. Zero `api/`, `frontend/`, `packages/` changes. → Confirmed: merge commit `eac496a` touches only the three `.github/workflows/*.yml`; this story file is the only other artifact.

## Dev Notes

### Why now (deadline, not hygiene)

- GitHub forces actions to run on Node 24 by default since **2026-06-02** and **removes Node 20 from the runners on 2026-09-16**. After that date, any action still targeting Node 20 stops working. This story closes the exposure while the fix is still a routine bump.
- The epic (33.3) allows "document the blocker and pin" if no Node 24-capable major exists - as of story creation none of the 11 bumps is blocked; every target major exists and the two quality workflows already passed on them (PR #277 CI).

### Execution shape (read this first)

- **This story is a PR review + merge + post-merge verification, not an edit session.** Do not hand-edit the workflow files to re-do what PR #277 already contains; landing the Dependabot PR keeps Dependabot's tracking state consistent (it stops re-proposing the same bumps). Only fall back to a manual `feature/epic-33-story-3-*` branch if PR #277 becomes unmergeable AND `@dependabot rebase`/`recreate` fails; in that case replicate the same 11 bumps and close #277 with a comment.
- **Merge rights:** epic flags 33.3/33.4 merges as [human]. Jean merges (or explicitly green-lights the merge in-session).
- **The PR's green CI is strong but incomplete evidence:** it exercised backend.yml and frontend.yml end-to-end with the new versions (path filters include the workflow files themselves), but Docker Publish never ran (push-only trigger). Post-merge Task 3 is where docker/* v4/v6/v7 and trivy v0.36.0 get their first real execution - budget for a possible fix-forward if one of them misbehaves.
- **Not in scope:** runner image changes, workflow logic changes, hardening (pinning to SHAs), or the other Dependabot PRs (#281 → 33.4; #37/#39/#41/#42 → 33.9). If a reviewer wants SHA-pinning, that is a new story - do not scope-creep it here.

### Current state inventory (as of story creation, post PR #283)

- `backend.yml`: checkout@v4 (x2), dorny/paths-filter@v3, shivammathur/setup-php@v2, actions/cache@v4, actions/upload-artifact@v4.
- `frontend.yml`: checkout@v4 (x2), dorny/paths-filter@v3, pnpm/action-setup@v4, actions/setup-node@v4, actions/upload-artifact@v4.
- `docker-publish.yml`: checkout@v4 (x3), docker/setup-buildx-action@v3 (x3), docker/login-action@v3 (x3), docker/metadata-action@v5 (x3), docker/build-push-action@v6 (x6), aquasecurity/trivy-action@0.35.0 (x3).
- No `actions/download-artifact` usage anywhere (so upload-artifact v7's paired download-artifact v8 requirement is moot).
- Open Dependabot PRs at story creation: #277 (actions group - THIS story), #281 (npm minor/patch - 33.4), #42 (typescript 6), #41 (@types/node 25), #39 (php 8.5 image), #37 (node 26 image) - the last four are majors deferred to 33.9.

### Previous story intelligence (33.1 / 33.2)

- Repo merge convention: merge commit (`gh pr merge N --merge --delete-branch`), PRs target `develop`.
- The `Backend`/`Frontend` aggregate jobs are the required branch-protection checks; they pass when the heavy `checks` job is skipped (no relevant path change) or succeeds. Keep this contract intact - it is why AC3 exists.
- 33.2 confirmed all standards docs now point at `composer gates` / `pnpm gates` as the canonical gate commands; this story does not touch them (workflow-only diff).
- Story files stay `ready-for-review` after merge (33.1/33.2 precedent); no status churn needed post-merge.

### Project Structure Notes

- Only `.github/workflows/*.yml` change, via the Dependabot branch `dependabot/github_actions/develop/actions-0962691545`. No api/frontend/packages source, no composer/pnpm manifests, no Docker files.
- BMAD story-branch convention does not apply to the merge itself (the change rides the Dependabot branch); this story file lands with the dev-record commit as usual.

### References

- Epic definition: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md` (branch `feature/epic-33-cleanup-and-standards`, commit 1b9e869) - story 33.3 AC, "Known issues" (deprecated runners), role note [human: org settings if needed], risk "GitHub Actions bump may be upstream-blocked".
- Dependabot PR #277 (the worklist): https://github.com/ArchiLAN-dev/archilan.fr/pull/277
- Node 20 runner timeline: GitHub changelog - Node 24 default since 2026-06-02, Node 20 removed 2026-09-16.
- upload-artifact v7 non-zipped artifacts (new `archive:` input, default unchanged): https://github.blog/changelog/2026-02-26-github-actions-now-supports-uploading-and-downloading-non-zipped-artifacts/
- trivy-action tag verified: `gh api repos/aquasecurity/trivy-action/git/refs/tags/v0.36.0` → exists.
- paths-filter latest: v4.0.2 (`gh api repos/dorny/paths-filter/releases/latest`).

## Dev Agent Record

### Agent Model Used

Claude Fable 5 (claude-fable-5)

### Debug Log References

- Pre-merge: `gh pr view 277` → mergeStateStatus CLEAN; `gh pr checks` → 6/6 SUCCESS; `gh api repos/aquasecurity/trivy-action/git/refs/tags/v0.36.0` → exists; `gh api repos/dorny/paths-filter/releases/latest` → v4.0.2.
- Merge: `gh pr merge 277 --merge --delete-branch` → develop `71e458a` → `eac496a`.
- Post-merge runs on `eac496a`: Backend Quality 28703999075 (SUCCESS, zero annotations), Frontend Quality 28703999059 (SUCCESS, 1 warn-only annotation: pnpm audit), Docker Publish 28703999100 (SUCCESS, 2 warn-only annotations: Trivy scans).

### Completion Notes List

- **Per-bump outcome: all 11 merged as-is, none dropped, none upstream-blocked.** checkout v4→v7, cache v4→v6, upload-artifact v4→v7, setup-node v4→v6, pnpm/action-setup v4→v6, dorny/paths-filter v3→v4, docker/setup-buildx-action v3→v4, docker/login-action v3→v4, docker/metadata-action v5→v6, docker/build-push-action v6→v7, aquasecurity/trivy-action 0.35.0→v0.36.0.
- **AC2 evidence:** the merge-push runs contain zero Node 20 deprecation annotations. The only annotations are the pre-existing by-design warn-only steps (Trivy CVE scan, pnpm audit high - `continue-on-error: true`, tracked by story 0.7 AC3), unrelated to this story.
- **AC3 evidence:** dorny/paths-filter v4 kept the outputs contract; both change-detection jobs and both aggregate required checks (`Backend`, `Frontend`) green on the merge push, so branch-protection gating is intact.
- **AC4 - story 33.4 scope note:** the `github_actions` Dependabot group is landed by this story (PR #277). Story 33.4's remaining scope is the npm minor/patch group (PR #281); majors stay deferred to 33.9 (PR #37 node-26 image, #39 php-8.5 image, #41 @types/node 25, #42 typescript 6).
- **AC5:** no repo code touched; `composer gates` / `pnpm gates` unaffected (CI ran the full backend and frontend gate sets green on the merge push, which doubles as the regression evidence).
- Story executed as a PR review + merge + post-merge verification (no hand-edits to workflows), per the execution-shape note; merge green-lit in-session by Jean ("go").

### File List

- `.github/workflows/backend.yml` (modified - via merged Dependabot PR #277)
- `.github/workflows/frontend.yml` (modified - via merged Dependabot PR #277)
- `.github/workflows/docker-publish.yml` (modified - via merged Dependabot PR #277)
- `_bmad-output/implementation-artifacts/33-3-github-actions-modernisation.md` (this story file)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-04 | Story executed: Dependabot PR #277 reviewed and merged into develop (`eac496a`), all 11 action bumps landed, post-merge verification green (3 runs SUCCESS, zero Node 20 deprecation annotations, gating intact). Status → ready-for-review. |
