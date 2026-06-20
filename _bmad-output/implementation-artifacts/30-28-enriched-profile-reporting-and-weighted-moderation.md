# Story 30.28: Enriched profile reporting + gravity-weighted moderation queue

Status: drafted

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a member who sees an inappropriate profile,
I want to report it with a precise category and severity (not just a free-text reason),
so that moderators see the most serious cases first and aren't flooded by low-value reports.

And as an admin moderator,
I want reports aggregated per account and ranked by a severity-weighted score with a threshold,
so that I only get pulled in when an account genuinely needs review.

Extends the report model (story 30.10) and the admin moderation queue (story 30.13).

### Why this exists (root cause)

A `ContentReport` today carries only a free `reason` string, and there is **no user-facing endpoint to
report a profile** — only comments can be reported (`ProfileCommentService::report`), even though the data
model already defines `TARGET_PROFILE` and the admin queue already renders profile targets. The queue lists
reports flat, newest-first, with **no severity, no per-account aggregation, no threshold** — so a single
malicious reporter or a flood of "I just don't like this" reports looks the same as a genuine
nudity/violence case. Discord decision (MasterKafey/Maxime, 2026‑06‑19): a report needs **Type** (photo,
bio, autre…) + **Contenu problématique** (nudité, violence…) + optional comment; surface the most
problematic first; the "Autre / Autre / sans commentaire" combination goes to a separate low-priority
bucket that is **not** notified; and an account is only escalated after **multiple** reports — weighted by
severity (PO decision: gravity-weighted, not a flat count).

## Acceptance Criteria

1. **Report a profile (self-excluded, idempotent).** `POST /api/v1/community/profiles/{slug}/report`,
   gated by `ApiAccessGuard::requireUser`, rejects reporting your own profile (`forbidden`), and is
   idempotent per `(reporter, TARGET_PROFILE, profileUserId)` — re-reporting is a no-op `ok` (mirrors the
   comment-report idempotency + unique constraint).
2. **Structured report payload.** A report carries `category` (whitelisted: `avatar`, `display_name`,
   `bio`, `social_link`, `comment`, `other`), `problem` (whitelisted: `nudity`, `violence`, `hate`,
   `harassment`, `spam`, `other`), and optional `comment` (≤ 500 chars). Domain validates both enums; the
   legacy free `reason` is kept (or derived) for backward compatibility. New columns on
   `community_content_report` (`category`, `problem`, `comment`) with a migration (+ `down()`); existing
   rows backfill to `other`/`other`.
3. **Severity weighting.** A `ReportSeverity` domain map assigns a weight per `problem`
   (e.g. nudity/violence/hate high, harassment/spam medium, other lowest). The `other`+`other`+no-comment
   combination weighs ~0 and is treated as the **uncategorized bucket**: visible only in a dedicated
   low-priority filter, never counted toward escalation, never notified.
4. **Per-account aggregation + weighted score.** The moderation read model groups unresolved reports by
   **reported account** (the `TARGET_PROFILE` user, or the author of a reported `TARGET_COMMENT`) and
   computes `weightedScore = Σ severity(problem)` over distinct unresolved reports. The admin queue can be
   viewed account-first, ordered by `weightedScore` desc.
5. **Threshold escalation + admin notification.** A configurable threshold (bound param, e.g.
   `community.moderation.escalation_threshold`) flags an account as **"à examiner"** once its
   `weightedScore` crosses it. Crossing the threshold notifies admins **once** (per account, debounced —
   not per report) via the existing `Notifier`; the uncategorized bucket never triggers this.
6. **Queue UX preserved + extended.** The existing report list (resolve / hide / restore) keeps working;
   the admin `/admin/moderation` page gains the account-first, severity-sorted view + the "à examiner"
   section + the low-priority uncategorized filter. Resolving the last unresolved report on an account drops
   its score below threshold.
7. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **api/ domain**: extend `ContentReport` with `category`, `problem`, `comment` (constructor + named
      factory; whitelist validation via domain consts); add `ReportSeverity` (`final readonly`, weight per
      problem + `isUncategorized()` helper). Migration adds the 3 columns (+ `down()`); backfill existing
      rows to `other`/`other`.
- [ ] **api/ report write**: `ReportProfileService` (or extend the comment path) → create a `TARGET_PROFILE`
      report for the resolved profile user, self-excluded, idempotent on the unique constraint. Resolve
      `{slug}` → user id via the existing profile query.
- [ ] **api/ presentation**: `POST /api/v1/community/profiles/{slug}/report` (`requireUser`) → validates the
      `category`/`problem` enums + comment length, calls the service. (Fills the missing user-facing profile
      report endpoint.)
- [ ] **api/ moderation read**: extend `AdminReportsQueryInterface` / `DbalAdminReportsQuery` +
      `ModerationService` to aggregate per reported account, compute `weightedScore`, expose the
      `category`/`problem`/`comment`/severity fields, and support the account-first ordering + uncategorized
      filter. Add `ReportQueryFilters` options (problem, category, uncategorized-only, account-first).
- [ ] **api/ escalation + notify**: compute the threshold crossing post-commit; emit one admin notification
      per account via `Notifier` (debounced — only on the transition below→above). Bind
      `community.moderation.escalation_threshold` in `services.yaml`.
- [ ] **frontend**: report dialog on the public profile (`/joueurs/[slug]`) with Type + Contenu
      problématique selects + optional comment; `/admin/moderation` account-first view (weighted score
      badge, "à examiner" section, uncategorized low-priority filter).
- [ ] **Tests**: backend functional (report a profile happy path; can't report self; idempotent;
      enum validation 422; weighted score + threshold escalation notifies admins once; uncategorized bucket
      never escalates/notifies; resolving drops the score). frontend jest (report dialog posts
      category/problem/comment; admin view renders weighted order).

## Dev Notes

- **Existing model is half-there**: `ContentReport` already supports `TARGET_PROFILE` and the admin queue
  + filters already branch on it — only the *user-facing profile report endpoint* and the *structured
  fields* are missing. Don't rebuild the queue; extend it. [Source: api/src/Community/Domain/ContentReport.php,
  api/src/Community/Application/ModerationService.php, api/src/Community/Application/ReportQueryFilters.php]
- **Idempotency precedent**: copy `ProfileCommentService::report` — check `reports->exists(...)`, catch
  `UniqueConstraintViolationException` for the concurrent dup. Unique key is
  `(reporter_id, target_type, target_id)`. [Source: api/src/Community/Application/ProfileCommentService.php]
- **Reported account resolution**: for `TARGET_PROFILE` the account = `target_id`; for `TARGET_COMMENT` the
  account = the comment's `authorId` (already loaded in `ModerationService::assemble`). Aggregate over both
  so a member spammed via comments also escalates. [Source: api/src/Community/Application/ModerationService.php]
- **Notifications post-commit**: reuse the `Notifier` port (story 30.12), emitted **after** the DB commit
  (AC-A4); debounce on the below→above transition so admins aren't spammed per report. Define an admin
  recipient set (ROLE_ADMIN is acceptable here — display/notification routing, not access control, so AC-M
  doesn't apply). [Source: api/src/Community/Application/Notifier.php]
- **Severity map** lives in Domain (pure, no config read); the *threshold* is an injected param (Application
  boundary) so ops can tune it without a deploy-time domain change.
- **Out of scope (→ story 30.29)**: acting on the account (warn / suspend / ban). This story only
  *surfaces and ranks*; the admin still resolves/hides as today. Account actions are a separate story.
- **DDD**: report write returns `void` (AC-A3); read returns DTOs/arrays; no `EntityManager`/`Connection`
  in Application (use the report repo + query interfaces). [Source: api/CLAUDE.md]

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]
- Report model + queue: [Source: _bmad-output/implementation-artifacts/] (30.10 report, 30.13 moderation)
- Follow-up: [Source: _bmad-output/implementation-artifacts/30-29-account-moderation-actions.md]
- Standards: [Source: api/CLAUDE.md], [Source: api/CLAUDE.md#cqrs-naming],
  [Source: api/CLAUDE.md#membership-access-control]