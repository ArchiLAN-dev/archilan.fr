# Epic 35 - Strict CQRS Write Path

Status: Stage 2 in progress (started 2026-07-17). Stage 1 complete (2026-07-17): every command service that
returned a failure discriminant now throws a typed `ApplicationFailure` mapped centrally by
`ApplicationFailureListener`. Stage 2 (typed result records) is now being delivered in full, on the explicit
"le plus clean possible" directive (Jean, 2026-07-17). Stage 3 (command/query split) stays deferred - see the
Stage 2 breakdown for why it is *not* the cleaner end-state for this codebase.
Date: 2026-07-11 (recorded), 2026-07-16 (planned)

## Origin

Story 33.17 surfaced that `api/CLAUDE.md` AC-A3 ("Command services return void") was contradicted by
the established convention: ~60 public methods across the 72 `Application/Command/` classes return
outcome arrays (`['found' => ..., 'errors' => ..., 'data' => ...]`) consumed by controllers to build
HTTP responses. Decision (Jean, 2026-07-11): amend AC-A3 to describe the current contract and enforce
the real invariant (no Doctrine entity returned from Application - validator rule since 33.17); the
move to strict CQRS is recorded here as its own future epic, to be taken up for real reasons (need to
scale/evolve read and write models independently), not doctrinal conformity.

## Goal (if/when planned)

Move the api write path from outcome-array returns to the strict form, in independently shippable
stages:

1. **Typed failures.** Replace outcome discriminants (`'outcome' => 'not_found'`, `'errors' => [...]`)
   with typed Application exceptions (`*NotFoundException`, `ValidationFailedException` carrying the
   field map), mapped centrally to HTTP status codes (kernel listener). Mechanical, context by context.
2. **Typed results.** Replace remaining associative-array returns with `final readonly` result records
   (ID/version/acknowledgement only - no read payloads). Kills the array-shapes at the
   Application/Presentation boundary.
3. **Command/query split in controllers.** Command returns void/minimal record; the response body is
   built by a separate query call (read-your-writes on the same DB). This is the substantive chantier:
   one extra read per write, full decoupling of write and read models; prerequisite for event sourcing
   or a physically split read model if ever needed.

## Constraints

- Stage 3 only pays for itself if a real driver appears (read-model scaling, eventual consistency,
  event sourcing). Stages 1-2 are worth doing on code-quality grounds alone and can land without 3.
- Epic-sized: touches all 72 command services, their controllers and tests. Per-context stories,
  gates green at every step, AC-P3/P4 (thin controllers) must keep holding.
- AC-A3 (as amended by 33.17) and the no-entity-return validator rule stay authoritative during any
  intermediate state; the validator rule set tightens further as stages land (e.g. stage 2 enables a
  "command returns void or a record, never array" rule).

## Stage 1 story breakdown (typed failures, per context)

One shared foundation, then convert contexts one at a time (each its own PR, gates green at every step).
Contexts ordered smallest-first (by count of command methods returning outcome arrays, measured 2026-07-16):

- **35.1 - Foundation + Content.** `Shared\Application\Exception` typed-failure hierarchy (an
  `ApplicationFailure` interface + base + `NotFound`/`Validation`/`Conflict`/`ServiceUnavailable`) and a
  central kernel exception listener (`Shared\Infrastructure\Http`) mapping any `ApplicationFailure` to the
  `{ error: { code, message, details? } }` envelope. Convert the Content context (`UploadPostCoverImageCommand`)
  as the first real proof; its controller drops the outcome branching.
- **35.2 - Payments + Sessions** (`TriggerHelloAssoSync`, 1 Sessions command).
- **35.3 - PersonalRuns** (2) · **35.4 - Events** (3) · **35.5 - GameSelection** (4) ·
  **35.6 - Registrations, public + message** (4 of 6: reserve/submit/cancel/message; adds `BadGatewayException`
  502) · **35.6b - Registrations admin** (`AdminRegistrationCancellation`, `AdminRegistrationModification` -
  deferred: their controllers audit-log *every* outcome, so conversion needs the logging moved into the
  command first) · **35.7 - Identity, validation-shaped admin/privacy** (3 of 8: `AdminChangeUserRole`,
  `AdminCreateAdminAccount`, `CreatePrivacyRightsRequest`) · **35.7b - Identity RegisterUser** (deferred:
  `register()` is the pervasive test user-creation helper returning `{user: User}`; converting ripples into
  ~12 test files - do it as its own PR) · **35.7c - Identity discriminant** (`ChangeUserSlug`
  - all slug errors -> 422, code->message map moved into the command via a `failSlug()` helper; `SaveSteamAccount`
  - invalid_input -> 422, not_found -> 404). **`LinkDiscordToAccount` + `HandleDiscordAuthCallback` are NOT
  converted**: they are OAuth *callback* commands whose outcome drives a `RedirectResponse` to the frontend
  (`?discord_error=...`), not an HTTP error code - their outcome discriminant is routing, not a typed failure,
  so it stays. Community's existing `CannotKudosOwnContentException` folds into the interface when Community is
  converted (a future need, not required for Stage 1).

**Stage 1 done.** Every command that mapped a failure discriminant to an HTTP error status now throws a typed
`ApplicationFailure`. Remaining outcome-arrays are legitimate success/routing discriminants (reserve's
reserved/already_registered, the Discord OAuth callbacks), not failures.

## Stage 2 story breakdown (typed result records, per context)

**Decision (Jean, 2026-07-17, "le plus clean possible, peu importe l'impact/temps"):** deliver Stage 2 in
full - **every command method returns `void` or a `final readonly` result record, never a raw `array`** - and
add the validator rule that gates it. But **NOT** the Stage 3 command/query split, because for this codebase
that is *more* machinery, not cleaner:

- the command already holds the data it just wrote; a separate read-query re-reads it (a redundant round-trip,
  "read your writes") purely for doctrinal separation;
- it would force the controller to make **two** Application calls (command + query), violating AC-P4 ("at most
  one Application service call per action") unless a facade is introduced everywhere - more indirection.
- Stage 3's only real payoff is decoupling read/write *models* to scale or event-source them independently;
  with no such driver, it is ceremony. Jean's original deferral stands and is *reinforced* by the clean-first
  lens: the cleanest reachable end-state is fully-typed records + one Application call, not void-command + query.

So a Stage 2 result record carries exactly what the caller renders: an id/status/slug ack for the simple ones,
a `final readonly` DTO for the ones that produce a read payload (preferring a shared DTO reused by the matching
query where one exists). No `array{...}` phpdoc shapes survive at the Application/Presentation boundary.

**Colocation:** a command's result record lives in `Application/Command/` with the command (taxonomy rule).

Contexts ordered to establish the record convention first, then roll out (each its own PR, gates green):

- **35.8 - Foundation/pilot: PersonalRuns.** `PersonalRunLifecycle` (5 methods) returns a `RunLifecycleResult`
  record instead of `array{runId, status}`; both controllers read `->runId`/`->status`. Establishes the
  colocated-record convention. HTTP bodies byte-identical. Done (PR #339).
- **35.9 - Registrations** (`ReserveRegistration` outcome+id -> record with a status enum; `RegistrationSubmission`). Done (PR #340).
- **35.10 - Identity acks** (`ChangeUserSlug` -> `SlugChangeResult` record). Done.
- **35.11 - Identity read-payloads** (`AdminChangeUserRole`, `AdminCreateAdminAccount`, `CreatePrivacyRightsRequest`,
  `RegisterUser` `{user: User}`) -> `final readonly` records (`AdminUserView` shared by the two admin commands,
  `PrivacyRightsResult`, `RegisteredUser` {id,email,roles} killing the raw-entity leak). `RegisterUser` fixture
  usage decoupled via a `FunctionalTestCase::registerUser()` helper (~10 tests). Done.
- **35.12 - Identity Discord routing** (`LinkDiscordToAccount`, `HandleDiscordAuthCallback`) -> routing **enum**
  outcome (`link()` returns a bare `DiscordLinkOutcome`; `handle()` returns a `DiscordAuthResult` record wrapping
  `DiscordAuthOutcome` + `?userId`, killing the raw-`User` leak). Still drives the `RedirectResponse`, now typed. Done.
- **35.13 - Self-contained upload** (`UploadTutorialImageCommand` -> `TutorialImageUpload` {key, url} record). Done.
  **Scope split (Jean, 2026-07-18):** the other three uploads (`UploadPostCoverImageCommand`,
  `UploadEventCoverImageCommand`, `ManageEventGalleryCommand`) do **not** return a self-contained shape - they
  delegate their return to the admin read-model facades (`AdminPostCatalog::get`, `AdminEventDrafts::get`), big
  untyped `array<string,mixed>` admin payloads shared with `list/get/create/update/...`. Typing them cleanly =
  typing those whole admin read-models (a shared `AdminPostView`/`AdminEventView` DTO reused by every method +
  the admin controller). That work belongs to the context read-model stories below, where the upload command
  returns the same shared DTO for free - not a separate "uploads" story.
- **35.14 - Content admin read-model** (`AdminPostCatalog` list/get/create/update/publish/unpublish -> shared
  `AdminPostView` DTO + `UploadPostCoverImageCommand` returns it; `AdminPostController` serializes it).
- **35.15 - Events admin read-model** (`AdminEventDrafts` list/get/create/update/transition/configurePrivateAccess
  -> shared `AdminEventView` DTO + `UploadEventCoverImageCommand`/`ManageEventGalleryCommand` return it;
  `AdminEventController` serializes it).
- **35.16 - Events/SessionConfig/WeeklyRuns/Community remainder** (`VerifyPrivateEventAccess`,
  `AdminUpdateSessionConfig`, `AdminCreate/UpdateWeeklyTemplate`, `LaunchWeeklyEntry`, `OptInToWeeklyRun`,
  `UpdateCommunityProfile`).
- **35.17 - Sessions + CLI reports** (`ForceEndSessionCommand`; the CatalogSync/GameSelection `Backfill*` /
  `SeedGameTutorials` / `CheckApworldUpdatesService` report arrays -> report records, for full consistency).
- **35.18 - Validator rule.** Add the `DddArchitectureValidator` gate "a command service returns `void`, a
  `final readonly` record, or an enum, never an `array`", update `api/CLAUDE.md` AC-A3 + the
  `StandardsDocsMatchToolingTest`. Ships last, once no command returns an array.
