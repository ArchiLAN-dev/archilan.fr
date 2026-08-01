# Story 9.43: Structured generation failure record (stop screen-scraping our own traceback)

Status: review
Related: 9.40 (failure parsing), 9.38 / 9.42 (preflight verdicts), 9.41 (notifications)

## Story

As a **player reading why my config broke a generation**,
I want **the message to be the world's actual, complete, readable error**,
so that **I can fix my YAML instead of decoding a truncated traceback fragment**.

## Context - two bugs in two days, same root cause

The player-facing message was reconstructed by regex from the generator's stderr. That
approach failed twice in production, in two different ways:

1. **Multi-line messages lost their meaning** (Atlyss, 2026-07-31): the world raises
   `OptionError("\n\n=== Atlyss YAML ERROR ===\n...")`, so the traceback line is
   `Options.OptionError:` with nothing after it. No exception line matched, and the finding
   fell back to the useless AutoWorld marker. Fixed by rebuilding multi-line messages
   (PR #436) - a patch on the heuristic.
2. **Long single-line messages lost their head** (Atlyss again, after the YAML was fixed):
   a fill/accessibility error lists every `(location, item)` pair on ONE line. The
   orchestrator keeps the last 2000 bytes of stderr, so the excerpt landed *inside* that
   line: no traceback, no `Type:` header, and a wall of text starting mid-word
   (`lete: Summore' Spectral Powder!), ...`). Verified: the stored excerpt was a single
   2000-character line.

Both come from the same place: we screen-scrape a traceback that **we ourselves print**.
`generate_multiworld.py` is our script - it can just tell us what failed.

## Acceptance Criteria

**AC1 - The generator emits the failure:** on any `Exception` out of `Generate.main()` /
`ERmain()`, `generate_multiworld.py` prints, as the LAST line of stderr, a single
machine-readable record:
`###ARCHILAN-FAILURE### {"type","message","player","slot","world"}`. The message is
whitespace-normalized (multi-line messages survive) and bounded **from the head** (1200
chars). `slot`/`player`/`world` come from the PEP 678 notes `AutoWorld.call_single`
attaches. The full traceback is still printed above the record for the admin log.
`SystemExit` is not caught and stdout still carries the output filename on success.

**AC2 - The API prefers the record:** the parser reads the last sentinel line and uses it
verbatim; when the record names no slot (failure outside a world hook), the existing text
heuristics still supply the attribution. The sentinel line never appears in the log shown
to a human.

**AC3 - The heuristics stay as a fallback:** older images and crashes that kill the
interpreter before the handler runs (OOM, segfault) keep producing an attributed finding
through the existing text parser. All prior parser tests stay green, unchanged.

**AC4 - Nothing unbounded reaches a badge:** every message is bounded (500 chars) and long
bracketed lists collapse to their size (`[… 40 entrées …]`); when a list was already cut
upstream, `[… liste tronquée …]` is used rather than a wrong count. The 9.42 handler uses
`summarize()` and never stores the raw excerpt.

**AC5 - Excerpt safety net (orchestrateur):** each stderr line is capped (300 runes) before
the tail is taken, so a single huge line can no longer swallow the whole budget; the
function is rune-based so a cut never yields invalid UTF-8.

**AC6 - Quality gates:** archipelago check green, orchestrateur `go test ./...` green, api
`composer gates` green.

## Tasks / Subtasks

- [x] Task 1: archipelago - structured record emitter (AC1). PR #12, merged; image rebuilt.
- [x] Task 2: orchestrateur - line-aware excerpt + rewritten contract tests (AC5). PR #16,
      merged; image rebuilt and redeployed locally.
- [x] Task 3: api - sentinel-first parsing, `summarize()`, list collapsing, bounding
      (AC2, AC3, AC4) + unit tests on the real transcripts.
- [x] Task 4: gates (AC6).

## Dev Notes

- The record goes to **stderr**, not stdout: on success the orchestrator reads stdout to get
  the generated filename, and on failure it captures stderr - so stdout must stay clean.
- Priority order in the parser is deliberate: record > AutoWorld marker > player-file block >
  last exception line. The record wins because it is the only source that cannot be mangled
  by truncation.
- The collapse of long lists is display-only (it never touches `cleanedLog`), so the admin
  log keeps every entry.
- Ops: this story needs BOTH images redeployed (archipelago for the emitter, orchestrateur
  for the excerpt). Until then the text fallback keeps working - no hard dependency.

## Dev Agent Record

### Agent Model Used

Claude Fable 5 (claude-fable-5)

### Completion Notes List

- The emitter was validated in isolation on three real shapes (Atlyss decorated OptionError,
  200-entry fill error, `Generate.main()` ValueError) before rebuilding the image.
- `preflightErrorExcerpt`'s contract changed (head-of-long-line instead of blind byte tail);
  its Go tests were rewritten rather than patched, since the old ones asserted the very
  behaviour that caused the bug.

### File List

- archipelago/generate_multiworld.py (own repo, PR #12)
- orchestrateur/internal/service/apworld_preflight.go + _test.go (own repo, PR #16)
- api/src/Sessions/Application/Support/GenerationFailureParser.php
- api/src/PersonalRuns/Application/Handler/RunSlotPreflightJobHandler.php
- api/tests/Unit/Sessions/GenerationFailureParserTest.php
