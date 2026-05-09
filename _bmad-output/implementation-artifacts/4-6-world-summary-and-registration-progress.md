# Story 4.6 - World Summary and Registration Progress

**Status:** done  
**Validation:** pnpm typecheck: 0 errors - ESLint game-selection-gate clean

## Changes

### Frontend only - `frontend/src/features/events/game-selection-gate.tsx`

**New type:**
- `CompletionStatus { isComplete, missingRequired, gameErrors }` - computed from selected game IDs, their options schemas, and current live option values

**State changes in `GameSelectionGate`:**
- Lifted `optionValues: Record<string, Record<string, OptionValue>>` up from `GameOptionPanel` - parent now holds all option values keyed by `gameId → optionKey → value`
- `optionValues` initialized from `data.selectedGamesWithOptions` on each data load (including after game selection saves via `loadKey` increment)
- `GameOptionPanel` is now a controlled component: receives `values` + `onValuesChange` callback instead of managing internal state

**New components:**

`RegistrationProgressIndicator` - step breadcrumb (Réservation ✓ → Jeux & options [active] → Récapitulatif) with `aria-current="step"` on the active step

`WorldSummaryPanel` - shows per-game summary for selected games:
- Game name + ✓/⚠ completion icon
- Key options (`visibility === "basic"` or `required`) with current value or "-" if missing
- Footer: "Sélection complète" or count of missing required options

`MobileSummaryBar` - fixed bottom bar (hidden on `lg:` breakpoint):
- Collapsed: shows count + completion icon + toggle chevron
- Expanded: inline `WorldSummaryPanel` in scrollable panel
- `aria-expanded` / `aria-controls` wired for accessibility

**Layout:**
- 2-column `lg:grid-cols-[1fr_18rem]` on desktop - main content left, sticky summary sidebar right
- `pb-24 lg:pb-0` on article to prevent content hiding behind mobile bar

**CTA ("Continuer vers le récap →"):**
- Enabled only when `completion.isComplete && saveState.kind === "saved"`
- Disabled `title` attribute explains why (unsaved selection vs. missing required options)
- Navigates to `/evenements/[slug]/inscription/[regId]/recap` (Story 4.7 destination)

**Accessibility:**
- `<p aria-live="polite" className="sr-only">` updates screen readers when `selectedIds` changes (game count announcement)

**`computeCompletionStatus` helper:**
- Returns `{ isComplete, missingRequired, gameErrors }` - `isComplete` is true when at least 1 game selected and all required options across selected games have a non-null, non-empty value in the live `optionValues` state

### Review Findings

- [x] [Review][Patch] WorldSummaryPanel does not list games selected locally before the selection is saved/reloaded, because it filters only `selectedGamesWithOptions` from the last API response [frontend/src/features/events/game-selection-gate.tsx:527]
- [x] [Review][Patch] RegistrationProgressIndicator is static and receives no completion/error state, so it cannot satisfy the acceptance criterion that progress shows errors [frontend/src/features/events/game-selection-gate.tsx:249]
