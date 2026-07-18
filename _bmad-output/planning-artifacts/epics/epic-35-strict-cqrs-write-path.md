# Epic 35 - Strict CQRS Write Path

Status: **Stage 2 complete (2026-07-18)** - every `Application/Command/` method returns `void`, a `final
readonly` record, or an enum, never a raw `array`, now gated by `DddArchitectureValidator::validateCommandArrayReturns`
(35.20). One documented allowlist entry (`AdminEditMembership`, a read-model delegation) remains, tracked as
follow-up 35.21. Stage 1 complete (2026-07-17): every command service that returned a failure discriminant
throws a typed `ApplicationFailure` mapped centrally by `ApplicationFailureListener`. Delivered on the explicit
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
- **35.14 - Content admin read-model** (`AdminPostCatalog` list/get -> shared `AdminPostView` **record** (first
  read-view record; existing `*View` are array-returning facades) + `UploadPostCoverImageCommand` returns it;
  both admin controllers serialize it, byte-identical, no controller edit). Write-outcome facade methods
  (`create/update/publish/unpublish`) stay `{found, errors}` per AC-A3 (Service, not Command). Done.
- **35.15 - Events admin read-model** (`AdminEventDrafts` list/get -> shared `AdminEventView` **record** (25
  fields; also the `event?` embedded in the create/update/transition/configurePrivateAccess outcome envelopes) +
  `UploadEventCoverImageCommand`/`ManageEventGalleryCommand` return it; all three admin controllers serialize it,
  byte-identical, no controller edit. Write-outcome envelopes (`{found, errors}`) stay per AC-A3. Fixed the
  long-stale 22-key `@return` docblocks (real payload = 25 fields). Done.
- **35.16 - Scattered record conversions** (`VerifyPrivateEventAccess` -> `?PrivateAccessResult`;
  `AdminUpdateSessionConfig` -> `SessionConfigResult`; `AdminCreate/UpdateWeeklyTemplate` -> shared
  `WeeklyTemplateResult`; `OptInToWeeklyRun` -> `WeeklyOptInResult`; `LaunchWeeklyEntry` -> `LaunchedEntry`).
  Six inline-shape commands across Events/SessionConfig/WeeklyRuns, all colocated records, controllers
  pass-through byte-identical, no controller edit. Done.
- **35.17 - Community `UpdateCommunityProfile`** (split from 35.16: its `{errorCode, errors}` is a
  validation-outcome discriminant, not a result payload -> Stage-1-style conversion: throws `ValidationException`
  ('Profil invalide.', default `validation_failed`/422), returns `void`, controller drops its `errorCode`
  branching; 422 body byte-identical via `ApplicationFailureListener`). Done.
- **35.18 - CLI report records** (CatalogSync/GameSelection maintenance commands: `SeedGameTutorials` ->
  `TutorialSeedReport`, `CheckApworldUpdatesService` -> `ApworldUpdateCheckReport`,
  `BackfillApworldDeployedVersionService` -> `ApworldDeployedVersionBackfillReport`, the three game
  `Backfill*` -> shared `GameBackfillReport`). Console + `AdminCatalogSyncController` + backfill unit tests read
  the records; output byte-identical. `BackfillActivity` excluded (returns `int`). Done.
- **35.19 - `ForceEndSessionCommand` + Session read-model** (split from 35.18). Resolved via a **Domain
  ValueObject** `Sessions/Domain/ValueObject/SessionView` (24 fields): `Session::payload()` returns it (a Domain
  method returning a Domain VO - the only DDD-legal way, since it cannot return an Application record), so
  `ForceEndSessionCommand` + the whole shared read surface (`SessionLifecycleManager`, `PlayerSessionConnection`,
  `SessionResultsQuery`, `SessionOrchestrator::listSessions`, `PlayerPatchController`) are typed at once. phpstan
  drove the consumer sweep; HTTP bodies byte-identical. Done. **No `Application/Command/` method returns an array
  now** -> the validator rule below is unblocked.
- **35.20 - Validator rule.** Added `DddArchitectureValidator::validateCommandArrayReturns` (mirrors the
  entity-return scan): public methods in `{Context}/Application/Command/` must not return `array`/`?array`.
  Rewrote `api/CLAUDE.md` AC-A3 (void/record/enum, never array) citing the new method +
  `COMMAND_ARRAY_RETURN_EXEMPT` + `validateApplicationEntityReturns`; `StandardsDocsMatchToolingTest` stays
  green. **The rule surfaced four commands the per-context sweeps missed** (multi-line `): array` signatures):
  converted `RecordSlotGoal`/`RecordWeeklyGoal` (shared `WeeklyGoalResult`) and `AdminCreateMembership`
  (`MembershipCreated`); exempted `AdminEditMembership` (delegates to the Membership admin read-model) via the
  allowlist -> **35.21**. Done. **Epic 35 Stage 2 complete: every command returns void/record/enum, gated.**
- **35.21 - Membership admin read-model** (follow-up surfaced by 35.20's rule). `AdminEditMembership` returns
  `AdminMembershipListQuery::findById` (a DBAL 10-field row with joined user/profile columns). Type that query's
  read shape (search/findById/findLatestByUserId -> a shared `MembershipView` record) so `AdminEditMembership`
  drops the `COMMAND_ARRAY_RETURN_EXEMPT` allowlist entry - same pattern as 35.14/35.15 (Content/Events admin
  read-models).
