# Story 9.40: Parse generation failures and attribute them to the faulty slot

Status: review

## Story

As a **run owner (and as the player whose config broke the seed)**,
I want **a failed generation to show WHICH slot broke it and WHY (the world's own actionable
message), instead of the generic "La génération a échoué côté serveur."**,
so that **the right person can fix their options and relaunch without an admin reading docker
logs on the host**.

## Context - why now

Two real incidents (2026-07-30 logs):

1. **2048** - `Exception: No game options for selected game "2048" found.` - root-caused and
   fixed by story 9.39 (orchestrateur-client v1.3.1), but the failure surfaced to nobody: the
   owner saw the generic message, the cause was found by reading `docker logs` on the server.
2. **A Bug's Life** - `Exception: Too many upgrade items based on LEVEL_CAPS: 141 items for
   16 locations. Disable some location categories/options or verify cap data.` followed by
   `Exception in <bound method BugsLifeWorld.create_items ...> for player 2, named
   masterkafey_ABL.` - the stderr names BOTH the faulty slot and an actionable fix, yet the
   user-facing message says nothing.

The plumbing already exists end to end; only parsing and presentation are missing:

- The orchestrateur captures the gen container stderr and sends it in the `session.crashed`
  webhook [Source: orchestrateur/internal/docker/client.go:629-641;
  orchestrateur/internal/service/session.go:265].
- The API stores it raw (`Session.lastLogs` via `recordCrash`) and records a single generic
  pseudo-slot validation error "Génération"
  [Source: api/src/Sessions/Application/Service/SessionLifecycleManager.php:211-252].
- The frontend already renders per-slot validation errors (configure-time errors use the same
  channel), and an admin-only raw-log endpoint already exists
  [Source: api/src/Sessions/Presentation/Controller/LogsController.php:28].

## Acceptance Criteria

**AC1 - Parser:** A pure, unit-testable `GenerationFailureParser` (Sessions
`Application/Support/`) takes the crash reason string and returns a typed result:
a list of `(slotName|null, message)` findings plus a cleaned log. Recognized patterns, in
priority order:

1. `Exception in <bound method ...> for player N, named {SLOT}.` → attribute to `{SLOT}`;
   the message is the LAST `SomeError: ...` / `Exception: ...` line of the traceback that
   precedes this marker (for the ABL case: the LEVEL_CAPS message).
2. Player-file errors from `Generate.py`: `File {stem}.yaml document #d (name: {name}) is
   invalid` blocks (and the `ERROR:root:Exception reading settings in file {stem}.yaml`
   variant) → attribute to the slot whose `slotName` equals the yaml file stem; message =
   the final `Exception: ...` line of that block.
3. Fallback (no slot attribution): last line matching `^[A-Z][A-Za-z]*(Error|Exception): .+`
   - same heuristic as the existing `classifyUploadError`
   [Source: api/src/Sessions/Infrastructure/Http/RunnerGateway.php:133-173].

**AC2 - Noise filtering:** Before parsing/storing, the reason is cleaned of known benign
lines: `DEBUG ...` lines and the `Warning: pip install failed for ...` blocks (client-only
deps in the sealed container - always noise). The cleaned version is what `recordLogs`
stores; parsing operates on it.

**AC3 - Per-slot surfacing:** `recordCrash` records the parsed findings through the existing
`recordValidationErrors` channel: attributed findings land on their REAL `slotName` with the
world's message verbatim prefixed by a short French lead-in (e.g. "La génération a échoué à
cause de ce slot : ..."). Only when no finding is attributable does the current generic
"Génération" pseudo-slot message remain (keeping the AC of story 17.11: the session never
hangs, the run is reset for retry - unchanged).

**AC4 - Owner-visible detail:** The run/session page shows, for a failed generation, the
per-slot error(s) in the existing validation-errors UI, plus a collapsible "détails
techniques" with the parsed exception excerpt (bounded, e.g. last 2000 chars of the cleaned
log) visible to the run owner. The full raw log remains admin-only via the existing
`/api/v1/admin/sessions/{id}/logs`.

**AC5 - Attribution correctness:** Slot matching uses `SessionSlot.slotName` (the
AP-authoritative name, story 9.37); a parsed name that matches no session slot degrades to
the unattributed fallback (never crashes, never mis-attributes).

**AC6 - Quality gates:** api `composer gates` green; frontend `pnpm gates` green. Parser
covered by unit tests fed with the two real stderr transcripts above plus a fill-error and
an unrecognizable input.

## Tasks / Subtasks

- [x] Task 1: `GenerationFailureParser` (AC1, AC2) - pure class + result record in
      `Sessions/Application/Support/`; unit tests with the real 2048 and ABL transcripts.
- [x] Task 2: wire into `recordCrash` (AC3, AC5) - map findings to session slots, keep
      generic fallback; adjust/extend `SessionLifecycleManagerTest`.
- [x] Task 3: API read side (AC4) - expose the bounded excerpt to the owner (extend the
      session/run detail payload; keep raw log admin-only).
- [x] Task 4: frontend (AC4) - per-slot error display already exists; add the lead-in
      wording + "détails techniques" accordion on the failed state.
- [x] Task 5: gates (AC6).

## Dev Notes

- Do NOT translate world messages: apworld authors write actionable English ("Disable some
  location categories/options") - show verbatim under a French lead-in.
- The `Error:` prefix format sent by the orchestrateur is
  `generate_multiworld.py exited N: {stderr}` - strip that envelope first.
- Weekly-gen sessions (`weekly-gen-*`) bypass `recordCrash` (webhook handled out-of-band,
  logged only) - out of scope here
  [Source: api/src/Sessions/Presentation/Controller/OrchestratorWebhookController.php:59-73].
- Related stories: 9.39 (2048 root cause), 9.38 (upload preflight - same stderr-excerpt
  philosophy), 17.11 (crash → failed state machine), 9.37 (slotName authority).
- Story 9.41 (notify the faulty player) consumes this parser's attribution - keep the
  parser result a reusable record, not strings baked into UI copy.

## Dev Agent Record

### Agent Model Used

Claude Fable 5 (claude-fable-5)

### Completion Notes List

- Parser implemented line-based (no offset captures) to stay phpstan-max friendly; findings
  deduplicated on (slotName, message).
- `SessionLifecycleManagerTest` did not exist; created the focused
  `SessionLifecycleManagerRecordCrashTest` instead (stubs + real Session entity through the
  draft → validating → ready → generating state machine).
- The mixed case (attributed + unattributed findings in one log) surfaces both: per-slot
  entries plus a "Génération" entry for the unattributed remainder.

### File List

- api/src/Sessions/Application/Support/GenerationFailureParser.php (new)
- api/src/Sessions/Application/Support/GenerationFailureFinding.php (new)
- api/src/Sessions/Application/Support/GenerationFailureReport.php (new)
- api/src/Sessions/Application/Service/SessionLifecycleManager.php (recordCrash + helper)
- api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php (generationLogExcerpt)
- api/tests/Unit/Sessions/GenerationFailureParserTest.php (new)
- api/tests/Unit/Sessions/SessionLifecycleManagerRecordCrashTest.php (new)
- frontend/src/features/personal-runs/types.ts
- frontend/src/features/personal-runs/personal-run-detail-page.tsx
