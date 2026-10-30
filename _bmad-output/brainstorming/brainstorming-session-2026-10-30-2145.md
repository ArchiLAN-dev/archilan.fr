---
stepsCompleted: [1, 2, 3, 4]
inputDocuments: []
session_topic: 'Admin discussion threads attached to any entity (generic annotation), starting with event registrations'
session_goals: 'Shape an epic: what a thread is, which entities and use cases, scope of the first slice (event registrations), what to leave for later'
selected_approach: 'ai-recommended'
techniques_used: ['Role Playing', 'Morphological Analysis', 'Reverse Brainstorming']
ideas_generated: 26
context_file: ''
session_active: false
workflow_completed: true
---

# Brainstorming Session Results

**Facilitator:** Jean
**Date:** 2026-10-30

## Session Overview

**Topic:** Admin discussion threads attachable to any entity, starting with event registrations
**Goals:** Shape an epic: what a thread is, use cases, target entities, scope of the first slice, what is deferred

### Context Guidance

- Overlap to settle with epic 39 (Discord staff forum, one folder per member).
- Strictly internal: the member concerned never sees a thread; GDPR applies to notes about people.

## Technique Selection

**Approach:** AI-Recommended Techniques
**Analysis Context:** generic admin threads with a first slice on event registrations

**Recommended Techniques:**

- **Role Playing:** start from the people who write and read threads (registration admin, LAN-day check-in, treasurer, moderator, future admin, the member concerned) to list concrete use cases.
- **Morphological Analysis:** split a thread into design axes (attachment, structure, visibility, lifecycle, attention, content, editing) and pick the first slice.
- **Reverse Brainstorming:** list the ways the epic could fail to turn them into guardrails and acceptance criteria.

**AI Rationale:** the topic is a product framing exercise on a concrete admin tool: usage first, then the generic model, then the risks.

## Technique Execution Results

### Role Playing

Roles explored: registration admin (before the event), check-in admin (event day), admin preparing the next event, moderator, volunteer at check-in.

**[Model #1] Note first, thread second** - the unit is a note (author, date, text) on an entity; replies are follow-ups that tell how the note evolved, not chat messages.
**[Model #2] Evolution as a timeline** - note and follow-ups read as a short dated timeline.
**[Model #3] A note can be closed** - a follow-up can mark it settled; it stays readable but folded.
**[Visibility #4] Eye-catching badge** - any registration with an open note carries a visible marker (icon + count) in admin lists, the check-in list first.
**[Visibility #5] Only open notes alert** - a settled note no longer lights the badge (avoids alert fatigue).
**[Visibility #6] Note readable without a click** - the latest open note shows on hover / row expand.
**[Scope #7] Registration notes live with their event** - never carried over automatically to another registration or the user.
**[Principle #11] No automation** - notes are never moved, copied or purged by the system; humans sort them by hand.
**[Cycle #12] Write before, read during, sort after** - notes are written ahead of the event, read on the day, and what lasts is copied by hand to the user account after the event.
**[Scope #13] Registration notes stay organisational** - behaviour is noted later on the user account, never on the registration; the epic 39 overlap only concerns future user notes.
**[Access #14] Admins only** - admins are the association's volunteers; they read and write; no narrower staff role.

Rejected by Jean: promote a registration note to the user (#8), "generic justified by the 2nd entity" (#9), automatic purge (#10). He will do these by hand.

**Key insight:** this is a living notebook, not a messaging tool. The open/settled state drives the badge, so it belongs in the first slice.

### Morphological Analysis

| Axis | Choice |
|---|---|
| Attachment | event registration (user account later, by hand) |
| Structure | note + follow-ups |
| Status | open / settled (settled = the only way to put a note away; no separate archive, no deletion yet) |
| Signal | badge + count, open notes only |
| Reading | panel on the row or hover |
| Content | plain text |
| Editing | any admin; original author kept; edit history on the main note only |
| Deletion | none in v1 |
| Reopen | yes |

**[Editing #15] Editable with history** - any admin can edit the main note; original author kept; each edit logged (who, when, previous text).
**[Lifecycle #16] Settled instead of deleted** - archive and settled are the same state: Jean keeps "settled"; no deletion in v1.
**[Lifecycle #17] Reopen a settled note** - the badge lights up again; the change is visible in the timeline.
**[Lifecycle #18] A status change is a follow-up** - settling or reopening is a follow-up with optional text ("settled: payment received in HelloAsso").
**[Editing #19] Follow-ups are not versioned** - only the main note keeps an edit history.

### Reverse Brainstorming

Failure scenarios proposed: ghost note (badge where nobody looks), leak to the member, graveyard of never-settled notes, orphan note, over-abstract generic engine, the hurtful word kept forever. Jean's main fear: **the over-abstract generic engine (#5)**.

**[Guardrail #20] Generic in data, specific in the screen** - a note stores a target type + target id; the registration screen gets its own simple panel; the timeline component is reused later, never a universal manager.
**[Guardrail #21] Closed list of target types** - an enum (`registration` only in v1); adding the user later = one value + one access check.
**[Guardrail #22] Target checked on write** - a small per-type port verifies the registration exists before a note is created (404 otherwise).
**[Guardrail #23] Badges in batch** - the registrations list gets the open-note count per registration in one query, not one per row.
**[Guardrail #24] Own context, no business coupling** - notes live in their own DDD context that knows no business entity; only an adapter on the Registrations/Events side checks a target.
**[Placement #25] Badge on the event registrations list** - check-in happens on the admin list of an event's registrations: the badge must be there.
**[Lifecycle #26] Cancelled registrations drop out of sight** - cancelled registrations are not shown, so their notes are not either; notes stay in the database untouched.

## Idea Organization and Prioritization

**Thematic Organization:**

1. **The model: a living notebook** - #1, #2, #3/#16, #15, #17, #18, #19. Plain-text note + follow-ups; open/settled is the only way to put a note away; reopen allowed; status change = follow-up with optional text; main note editable by any admin with history, original author kept; follow-ups not versioned; no deletion.
2. **Visibility on the day** - #4/#25, #5, #6. Badge + count of open notes on the event registrations list (the check-in screen); latest open note readable without a click.
3. **Scope and principles** - #7/#13, #11/#12, #14, #26. Organisational notes living with their event; no automation; admins only; cancelled registrations out of sight.
4. **Architecture against over-abstraction** - #20 to #24. Generic in data (target type + id), specific in the screen; closed enum (`registration` only); target checked on write; badges in batch; own DDD context with no business coupling.

**Prioritization Results (epic 46 "Notes admin"):**

| Story | Content | Ideas |
|---|---|---|
| 46.1 Notes foundation | Own context, target enum, target check on write, create / list / edit with history / follow-up / settle / reopen; API only, admins only | #1, #3, #11, #14-#19, #21, #22, #24 |
| 46.2 Notes on a registration | Timeline panel in the admin registration view (write, follow up, settle, reopen, see edit history) | #2, #7, #13, #20 |
| 46.3 Badge on the registrations list | Batched open-note count, visible badge, latest open note readable without a click | #4-#6, #23, #25, #26 |

**Deferred (out of epic):** notes on the user account (one more enum value); will need the split with the epic 39 Discord staff forum and GDPR deletion settled at that point.

**Action Planning:** write the epic file `_bmad-output/planning-artifacts/epics/epic-46-notes-admin.md`, then create story 46.1 with bmad-create-story.

## Session Summary and Insights

**Key Achievements:**

- The topic moved from "admin threads" to "a living notebook": notes first, follow-ups only to tell how a note evolves.
- The open/settled state became the core of the design because it drives the check-in badge.
- Jean's main fear (an over-abstract generic engine) produced five architecture guardrails.

**Session Reflections:** Jean consistently chose the minimal option (no automation, no deletion, no promotion, no extra role), so the epic stays small: three stories, one entity. Short, decisive answers made a long divergent phase unnecessary.
