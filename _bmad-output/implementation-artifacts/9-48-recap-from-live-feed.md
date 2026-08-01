# Story 9.48: Build the recap from the live feed instead of the spoiler

Status: review
Related: epic 32 (persisted feed), 9.16 (run archival), 32.4 (recap superlatives)

## Story

As a **player reading the recap of a finished run**,
I want **it to describe what actually happened during the session**,
so that **the graph and the superlatives reflect the game we played, and races - which have
no spoiler at all - finally get a recap too**.

## Context

`BuildSessionRecapJobHandler` parses the **generation spoiler** to produce the item-exchange
graph. The spoiler describes **the seed**: every placement decided at generation time,
including items nobody ever found. The persisted feed (`session_feed_event`, epic 32)
describes **the session**: every item actually sent, with sender, receiver, receiver game,
progression flags and timestamps.

Two consequences of the switch, both accepted:

- an item never collected no longer appears - a run abandoned midway shows the graph of what
  was played, not of what was placed;
- **seeds generated in race mode carry no spoiler**, so those sessions have no recap today.
  They get one for free.

## Decision - clean replacement, no spoiler fallback

Chosen over a live-first/spoiler-fallback hybrid: keeping both paths would preserve the
ambiguity the change is meant to remove. Sessions predating the persisted feed keep their
already-built recap projection (nothing rebuilds them), but a manual rebuild of such a
session now yields an empty graph. Accepted.

## Acceptance Criteria

**AC1 - Live source:** the recap graph is built from the session's persisted feed events of
type `item`: one node per slot seen, one aggregated edge per sender->receiver pair with its
item count, and local items counted when sender and receiver are the same slot.

**AC2 - Same projection, same UI:** the builder produces the existing `RecapGraph`, so the
handler's slot reconciliation, the superlatives calculator, the stored projection and the
public recap page are untouched. No frontend change, no migration.

**AC3 - Games resolved from the feed:** a node's game comes from the feed, which names both the sender's and the receiver's game; a slot whose game the feed never carried keeps an empty one rather than a wrong one.

**AC4 - No spoiler dependency in the recap path:** the handler no longer reads the spoiler
artifact, and `SpoilerGraphParser` is deleted. The spoiler **reader port stays** - the
spoiler download feature (`PersonalRunSpoilerDownload`) still uses it.

**AC5 - Empty is not a failure:** a session with no feed events yields an empty graph and a
stats-only recap, exactly as a missing spoiler did before - never an exception.

**AC6 - Quality gates:** api `composer gates` green.

## Tasks / Subtasks

- [x] Task 1: `FeedGraphBuilder` in `Sessions/Application/Support` turning feed events into a
      `RecapGraph` (AC1, AC3, AC5) + unit tests.
- [x] Task 2: handler switches to the feed repository, drops the spoiler reader and parser
      (AC2, AC4); update the `RecapGraph` docblock that still names the parser.
- [x] Task 3: delete `SpoilerGraphParser` and its tests (AC4).
- [x] Task 4: gates (AC6).

## Dev Notes

- `SessionFeedEventRepositoryInterface::findBySessionId()` already exists and returns the
  whole feed for a session; the builder is pure and takes the events as a parameter.
- Slot names in the feed (`sender_name` / `receiver_name`) are the same AP slot names the
  handler already reconciles against `SessionSlot::getSlotName()`, so the join keeps working
  unchanged.
- Out of scope: new live-only superlatives (first goal, longest dry spell…). The existing
  metrics keep their definitions - only their input changes.
