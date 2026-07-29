# Story 34.7: Measurement & tooling (frontend/ CI + external)

Status: review (code + deployed baseline done; GSC verification + sitemap submission + off-site = human handoff, documented)

## Story

As the ArchiLAN association wanting to know whether the epic-34 SEO work actually moves rankings,
I want Search Console set up, a Lighthouse baseline in CI (advisory), and a written measurement plan,
so that the epic's impact is observable instead of assumed, and the off-site actions are handed off clearly.

## Context

Final story of epic 34. Everything indexable is now in place (sitemap 34.1, metadata/canonical 34.2,
structured data 34.3, ISR + stable images 34.4, perf/fonts/headers 34.5, keyword copy 34.6). What's missing
is **measurement**: Google Search Console (GSC) to watch impressions/positions, a Lighthouse baseline, and a
short doc tying it together plus the off-site human action list.

Two parts, split by who can do them:

- **Code (this PR)** - env-driven GSC verification meta tag (so verifying is a config change, no code edit),
  an advisory Lighthouse CI workflow + config, and the measurement doc.
- **Human/ops (handoff)** - actually verifying the GSC property and **submitting the sitemap** (needs the
  association's Google account), plus the off-site actions (Google Business Profile, directories, backlinks).

## Acceptance Criteria

1. **AC1 - GSC verifiable without a code change.** The root layout renders a
   `google-site-verification` meta tag when `NEXT_PUBLIC_GSC_VERIFICATION` is set (empty by default -> no
   tag). So the human step is: paste the token into the env, redeploy, click "Verify" - no PR. Sitemap
   submission stays a human action (documented). [human: GSC account access]
2. **AC2 - Lighthouse baseline (advisory).** A Lighthouse CI config + a **non-blocking** workflow
   (manual `workflow_dispatch`) runs Lighthouse (mobile) against the home page, uploads the report, and
   asserts perf/a11y/SEO/best-practices at **warn** level (never fails). Thresholds documented. The event
   detail page + full before/after run stay a deployed-env step (needs the API + real data), noted in the
   measurement doc.
3. **AC3 - Measurement doc.** `docs/seo-measurement.md`: baseline date, the target queries to watch in GSC
   (from the 34.6 keyword map), how to read the LHCI report, and the **off-site human action list** (Google
   Business Profile, association directories, partner-community backlinks) explicitly handed to the
   association.
4. **AC4 - Gates.** `pnpm gates` green; the new CI workflow does not destabilise the existing required
   checks (it is a separate, non-required, manual-dispatch workflow).

## Tasks / Subtasks

- [x] Task 1: env-driven GSC verification tag (AC: 1)
  - [x] `src/lib/env.ts`: add `gscVerification` from `NEXT_PUBLIC_GSC_VERIFICATION` (optional, default `""`).
  - [x] `src/app/layout.tsx`: add `verification: { google: env.gscVerification }` to the root metadata
        **only when non-empty** (Next renders `<meta name="google-site-verification" ...>`).
  - [x] `frontend/.env.example`: document `NEXT_PUBLIC_GSC_VERIFICATION` (empty; the token from GSC's
        HTML-tag method).
- [x] Task 2: Lighthouse CI (advisory) (AC: 2, 4)
  - [x] `frontend/lighthouserc.json`: `collect` (startServerCommand `pnpm start`, url home, mobile default,
        3 runs), `assert` all **warn**-level thresholds, `upload` to temporary-public-storage.
  - [x] `.github/workflows/lighthouse.yml`: `workflow_dispatch` only; build the frontend, run
        `pnpm dlx @lhci/cli autorun`, `continue-on-error: true`. Separate job, **not** a required check.
- [x] Task 3: measurement doc (AC: 3)
  - [x] `docs/seo-measurement.md`: baseline date, target-query watchlist (from 34.6), LHCI how-to, and the
        off-site action checklist handed to the association.
- [x] Task 4: verify + ship (AC: 4)
  - [x] `pnpm gates` green; dev smoke that no verification tag renders when the env is unset, and one
        renders when set. PR to `develop`.

## Dev Notes

### GSC verification (AC1) - why the env-tag approach

Two GSC verification methods: **DNS TXT record** (done entirely in the DNS zone, no repo change) or **HTML
meta tag** (`<meta name="google-site-verification" content="TOKEN">`). We support the meta-tag method via an
env var so the association can verify without a developer: set `NEXT_PUBLIC_GSC_VERIFICATION`, redeploy,
click Verify. Next's `Metadata.verification.google` renders the exact tag. Empty env -> no tag (no stray
empty meta). If the association prefers DNS, they just skip the env - both paths work.

Sitemap submission (`https://archilan.fr/sitemap.xml`, live since 34.1) is done **in the GSC UI** after
verification - a human action, not code.

### Lighthouse CI (AC2) - advisory, and why home-only in CI

- LHCI needs the app running. In CI there is **no API**, so the home page degrades gracefully (events fetch
  returns `[]`, per 34.1) and still renders - good enough for a shell perf baseline. The **event detail
  page needs real data** (`getPublicEvent` -> `notFound()` without the API), so it cannot be measured in CI;
  its Lighthouse run is a deployed-env step, documented in the measurement doc (this is also 34.5 AC5's
  before/after).
- **Advisory** means: `workflow_dispatch` only (never on PR pushes, so PR CI stays fast and green), all
  assertions at `warn` level, and `continue-on-error: true` - the workflow can surface numbers but can never
  block a merge. It is a *separate* workflow, not added to the required `Frontend Quality` checks.
- `@lhci/cli` is run via `pnpm dlx` (CI-only) - not added to `package.json` devDependencies, keeping the app
  install lean.

### Thresholds (documented, warn-only)

`performance >= 0.8`, `accessibility >= 0.9`, `seo >= 0.9`, `best-practices >= 0.9` - all `warn`. These are
starting targets to watch, not gates; tighten later once a deployed baseline exists.

### House rules

- AC-ENV1: `NEXT_PUBLIC_GSC_VERIFICATION` goes through `env.ts` (never `process.env` in `src`). `env.ts`
  gains an optional-string reader (default `""`) alongside the existing `requireString`/`requireUrl`.
- `pnpm gates`; the new workflow must not touch the required checks.

### Project Structure Notes

- New: `frontend/lighthouserc.json`, `.github/workflows/lighthouse.yml`, `docs/seo-measurement.md`.
- Edited: `frontend/src/lib/env.ts` (gscVerification), `frontend/src/app/layout.tsx` (verification tag),
  `frontend/.env.example` (doc the env).
- No API change. `pnpm gates` only for the app; the LHCI workflow is separate + advisory.

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-34-seo-search-visibility.md` (story 34.7, "measurement
  before and after", "off-site SEO out of repo scope - human action list").
- Sitemap (34.1): `frontend/src/app/sitemap.ts` -> `/sitemap.xml`. Keyword map (34.6): the story's worklist.
- Existing frontend CI: `.github/workflows/frontend.yml` (the required `Frontend Quality` job).
- Standards: `frontend/AGENTS.md` (AC-ENV1); root `CLAUDE.md` (gates, Gitflow, no em-dashes).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- **AC1 (GSC tag)**: `env.gscVerification` (from `NEXT_PUBLIC_GSC_VERIFICATION`, optional, default `""`)
  drives `verification: { google }` in the root metadata, spread in only when non-empty. Dev smoke: **0**
  tags when unset, `<meta name="google-site-verification" content="...">` when set. Verifying GSC is now a
  config+redeploy, no code edit (or DNS TXT, entirely off-repo).
- **AC2 (Lighthouse)**: `frontend/lighthouserc.json` (home page, 3 runs, mobile default, all thresholds
  `warn`) + `.github/workflows/lighthouse.yml` (`workflow_dispatch` only, `continue-on-error: true`,
  `pnpm dlx @lhci/cli`). Strictly advisory: not a required check, never fails, never runs on PR pushes -
  PR CI stays fast/green. Both files validated (YAML + JSON parse).
- **AC3 (doc)**: `docs/seo-measurement.md` - GSC verify+submit steps, the target-query watchlist from the
  34.6 keyword map, the LHCI how-to + thresholds, the deployed-env before/after table (34.5 AC5), and the
  off-site action checklist (Google Business Profile, directories, backlinks) handed to the association.
- **AC4**: `pnpm gates` green (typecheck 0, lint 0 errors, jest 194/194, build clean). The Lighthouse
  workflow is separate and non-required - the `Frontend Quality` checks are untouched.
- **Human handoff (remaining)**: verify the GSC property (DNS or the env tag) + submit `/sitemap.xml` in
  GSC + the off-site actions. All documented in `docs/seo-measurement.md`.

### File List

- `frontend/src/lib/env.ts` (optionalString reader + gscVerification)
- `frontend/src/app/layout.tsx` (verification.google when set)
- `frontend/.env.example` (NEXT_PUBLIC_GSC_VERIFICATION)
- `frontend/lighthouserc.json` (new)
- `.github/workflows/lighthouse.yml` (new)
- `docs/seo-measurement.md` (new)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-16 | Story created from epic 34 (final story). Split into code parts (env-driven GSC tag, advisory LHCI, measurement doc) and the human handoff (verify the GSC property + submit the sitemap + off-site actions). Status: in-progress (code this PR). |
| 2026-07-16 | Code parts implemented: env-driven GSC verification tag, advisory Lighthouse workflow + config, measurement doc. `pnpm gates` green + dev smoke of the tag both states. GSC verify/submit + off-site actions remain the documented human handoff. |
| 2026-07-29 | Deployed baseline recorded in `docs/seo-measurement.md` (home 45/100/96/100, event detail 92/96/96/100, mobile). Two findings: raw static assets (10.6 MB hero + 2.4 MB logo) recompressed in-repo + new jest weight gate; prod `/_next/image` passthrough on local files documented as a post-deploy check. Advisory CI Lighthouse workflow dispatched once (first artifact). Remaining: GSC verify + sitemap submit + MinIO media-public policy/env (34.4 handoff) + off-site actions - all human/ops, documented. Status -> review. |
