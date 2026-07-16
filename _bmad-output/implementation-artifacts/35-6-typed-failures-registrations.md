# Story 35.6: Typed failures - Registrations (public + message) (api/)

Status: done

## Story

As a maintainer continuing epic 35 Stage 1,
I want the public registration commands (reserve, submit, cancel) and the admin message command to throw the
shared typed failures instead of returning outcome arrays,
so that the Registrations context largely adopts the 35.1 foundation and its controllers get thinner, HTTP
unchanged.

## Context

Epic 35, Stage 1, sixth story - the thorniest context. Registrations has 6 outcome-array commands. This story
converts **4** of them; the other 2 are deferred (see Scope). Adds one exception type: **`BadGatewayException`
(502)** for the mailer-refused case.

Exact mappings to preserve:

| Command | mapping |
|---|---|
| `ReserveRegistration::reserve` | email unverified -> 403 `email_not_verified` ; event missing/private -> 404 `not_found` "Événement introuvable." ; not eligible -> 422 `not_eligible` (+ `{registration:[reason]}`) ; capacity full -> 409 `capacity_full` "Cet événement est complet." ; **reserved (201) + already_registered (200) are both successes** returning `{ outcome, registrationId }` |
| `RegistrationSubmission::submit` | null -> 404 `not_found` "Inscription introuvable." ; error(code,message) -> 422 (self-carried) ; confirmed -> `{ registrationId, eventTitle, selectedGameIds }` |
| `RegistrationCancellation::cancel` | null -> 404 `not_found` "Inscription introuvable." ; error(code,message) -> 422 (self-carried) ; cancelled -> void |
| `SendMessageToRegistrant::send` | not_found -> 404 "Inscription introuvable." ; send_failed -> **502** `message_send_failed` "L'envoi du message a échoué." ; sent -> the `sentAt` string |

## Acceptance Criteria

1. **AC1 - BadGatewayException.** Add `Shared\Application\Exception\BadGatewayException` (502, default code
   `bad_gateway`) + a unit test.
2. **AC2 - Reserve.** `reserve` throws `ForbiddenException` (`email_not_verified`) / `NotFoundException` /
   `ValidationException` (`not_eligible`, with the reason detail) / `ConflictException` (`capacity_full`),
   each committing/rolling back its transaction **before** throwing; a `catch (ApplicationFailure)` before the
   generic catch prevents a double-rollback. Success returns the `{ outcome: reserved|already_registered,
   registrationId }` discriminant (the controller keeps the 200/201 distinction). Controller drops the 4
   failure branches.
3. **AC3 - Submit + Cancel.** `submit` -> `{ registrationId, eventTitle, selectedGameIds }`, throwing
   NotFound / Validation (self-carried code+message). `cancel` -> void, throwing NotFound / Validation.
   Both public controller actions drop their null/error branches.
4. **AC4 - Message.** `send` -> the `sentAt` string, throwing NotFound / BadGateway (502). The admin `message`
   action drops the two branches and keeps its success-only audit log.
5. **AC5 - Contract unchanged.** Statuses/codes/messages/bodies identical. The Registrations functional suite
   stays green (one direct-call unit assertion updated to expect the thrown `ConflictException`).
6. **AC6 - Gates.** `composer gates` green.

## Tasks / Subtasks

- [x] Task 1: `BadGatewayException` (502) + unit test.
- [x] Task 2: `ReserveRegistration` throws (transaction-safe) + keeps the dual-success discriminant; controller thinned.
- [x] Task 3: `RegistrationSubmission` (throws + returns data) + `RegistrationCancellation` (throws + void); controllers thinned.
- [x] Task 4: `SendMessageToRegistrant` (throws NotFound/BadGateway + returns sentAt); admin `message` action thinned.
- [x] Task 5: verify + ship - `composer gates` (static + isolated Registrations functional). PR to `develop`.

## Dev Notes

### Scope - 2 admin commands deferred (audit-log coupling)

`AdminRegistrationCancellation` and `AdminRegistrationModification` are **NOT** converted here. Their
controllers log `admin.registrations.{cancel,update}` with `$result['outcome']` for **every** outcome
(including failures) before branching. Converting them to throw would silently drop the failure-path audit
logs - an observable behaviour change. They need the audit logging moved into the command (with an `adminId`
param) or a listener that carries admin context; that redesign is a follow-up (35.6b), not folded in here to
keep this conversion behaviour-preserving.

### Reserve - transaction + dual success

`reserve` runs under an exclusive lock in a transaction with a `catch (\Throwable){ rollBack }`. Each business
failure **commits (or rolls back) before throwing**, so a `catch (ApplicationFailure){ throw }` is added
**before** the generic catch to avoid rolling back an already-finalised transaction. `already_registered`
(200) and `reserved` (201) are both **successes** - they stay as a `{ outcome, registrationId }` return so the
controller keeps the status distinction (Stage 1 only types the *failures*).

### Self-carried code/message

`submit` and `cancel` returned `{ outcome: 'error', code, message }`; the controller echoed `code`+`message`
into a 422. Converted to `throw new ValidationException($message, [], $code)` - identical body.

### 502 for the mailer

`send_failed` was a 502 (bad gateway - the email service refused), not a 503. Hence the new
`BadGatewayException` rather than reusing `ServiceUnavailableException` (503).

- House rules: exceptions in `Shared/Application/Exception/`. phpstan max, Yoda, strict types. `composer gates`.

### References

- Foundation: 35.1-35.5. Convert:
  `src/Registrations/Application/Command/{ReserveRegistration,RegistrationSubmission,RegistrationCancellation,SendMessageToRegistrant}.php`
  + `src/Registrations/Presentation/Controller/{RegistrationController,AdminRegistrationController}.php`.
- Epic: `_bmad-output/planning-artifacts/epics/epic-35-strict-cqrs-write-path.md` (Stage 1 breakdown).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- Added `BadGatewayException` (502) + unit test (the exception set now spans 404/403/409/422/502/503).
- `ReserveRegistration`: throws the 4 failures transaction-safely (`catch (ApplicationFailure)` passthrough
  before the generic rollback catch); returns the `{ outcome: reserved|already_registered, registrationId }`
  dual-success discriminant. Controller dropped the 4 failure branches, kept the 200/201 split.
- `RegistrationSubmission::submit` -> `{ registrationId, eventTitle, selectedGameIds }` array, throwing
  NotFound / Validation (`games_required`). `RegistrationCancellation::cancel` -> void, throwing NotFound /
  Validation (`cancellation_not_allowed`). Both public controller actions thinned.
- `SendMessageToRegistrant::send` -> `sentAt` string, throwing NotFound / BadGateway (`message_send_failed`,
  502). The admin `message` action thinned; its success-only audit log preserved.
- **Deferred**: `AdminRegistrationCancellation` + `AdminRegistrationModification` (they log every outcome -
  converting would drop failure audit logs), documented for a follow-up.
- Contract identical: one direct-call unit assertion in `ReserveRegistrationTest` updated to expect the
  thrown `ConflictException` (it invoked the command directly and asserted the old return); all HTTP-level
  tests unchanged.
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK, rector OK, **phpunit 1543 tests / 10541
  assertions** (isolated DB).

### File List

- `api/src/Shared/Application/Exception/BadGatewayException.php` (new)
- `api/tests/Unit/Shared/ApplicationExceptionTest.php` (BadGateway case)
- `api/src/Registrations/Application/Command/ReserveRegistration.php`
- `api/src/Registrations/Application/Command/RegistrationSubmission.php`
- `api/src/Registrations/Application/Command/RegistrationCancellation.php`
- `api/src/Registrations/Application/Command/SendMessageToRegistrant.php`
- `api/src/Registrations/Presentation/Controller/RegistrationController.php`
- `api/src/Registrations/Presentation/Controller/AdminRegistrationController.php` (message action)
- `api/tests/Functional/ReserveRegistrationTest.php` (direct-call assertion updated)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-16 | Story created + implemented (epic 35 Stage 1, 35.6). Registrations: 4 of 6 commands converted (reserve, submit, cancel, message) + `BadGatewayException` (502); the 2 audit-logging admin commands deferred. `composer gates` green (1543 tests). Status: done. |
