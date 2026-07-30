# Story 9.41: Notify the player whose slot broke the generation

Status: review
Depends on: 9.40 (generation failure parsing / per-slot attribution)

## Story

As a **player whose configuration made a multiworld generation fail**,
I want **to be notified directly (in-app, with the actionable message and a link to my slot
config)**,
so that **I can fix my options before the next launch attempt, without waiting for the run
owner to chase me**.

## Context

With story 9.40 the failed generation is attributed to a slot, but only people who open the
run page see it - typically the owner, while the person who can actually fix the config is
another player. The notification pipeline already exists (Community context: `Notifier`
port, in-app notifications dispatched post-commit - epic 30); this story routes generation
failures through it.

## Acceptance Criteria

**AC1 - Trigger:** When `recordCrash` records a generation failure attributed to one or
more slots (9.40 parser output), each faulty slot's participant user receives an in-app
notification: short French title, the world's message (verbatim excerpt), and a deep link
to that slot's configuration page for the run.

**AC2 - Owner notified too:** The run owner receives one notification per failed
generation (not per slot) summarizing: which slot(s) failed, or "cause non identifiée" when
the parser could not attribute. If the owner IS the faulty player, they get only the
player-facing notification.

**AC3 - Post-commit dispatch:** Notifications are dispatched AFTER the crash transaction
commits (Messenger job, consistent with AC-A4 and the epic 30 Notifier post-commit
pattern) - a notification failure never breaks the crash handling.

**AC4 - No spam:** Re-launching and failing again MAY re-notify (each generation attempt is
a real event), but one generation failure = at most one notification per recipient.
Unattributed failures notify only the owner.

**AC5 - Scope:** Personal runs and event sessions (the flows going through `recordCrash`).
Weekly-gen sessions stay out of scope (no human recipient at generation time).

**AC6 - Quality gates:** api `composer gates` green; frontend `pnpm gates` green (frontend
work should be nil or minimal - the in-app notification UI already exists).

## Tasks / Subtasks

- [x] Task 1: recipient resolution - map faulty `slotName` → participant user id (personal
      runs: `RunParticipant`; event sessions: registration slot mapping) in the Sessions
      Application layer, behind an interface if a cross-context query is needed.
- [x] Task 2: Messenger job + handler dispatching via the `Notifier` port
      [Source: api/src/Community/Application/Support/Notifier.php] after commit; unit tests
      with mocks (faulty player, owner, owner==player dedup, unattributed).
- [x] Task 3: deep link - reuse the existing slot-config route on the run page; verify the
      notification payload shape matches the in-app notification component.
- [x] Task 4: gates.

## Dev Notes

- Respect the DDD layer rules for the cross-context call (Sessions → Community): inject the
  existing port/interface, never a Community concrete class; follow how other contexts
  already emit notifications (achievements/friends flows, epic 30).
- Wording: title "Ta config a fait échouer la génération" + body = world message verbatim;
  owner summary "La génération de {run} a échoué (slot {X})".
- Related: 9.40 (parser + attribution this story consumes), epic 30 (notification
  infrastructure), 17.11 (crash handling state machine).

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List
