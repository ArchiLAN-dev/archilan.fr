# Epic 35 - Strict CQRS Write Path

Status: Stage 1 complete (2026-07-17). Every command service that returned a failure discriminant now
throws a typed `ApplicationFailure` mapped centrally by `ApplicationFailureListener`. Stage 2 (typed result
records) next, on code-quality grounds; Stage 3 stays deferred until a real driver (read-model scaling /
eventual consistency / event sourcing).
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

Stage 2 (typed result records) and the tightened validator rule follow once Stage 1 covers every context.
