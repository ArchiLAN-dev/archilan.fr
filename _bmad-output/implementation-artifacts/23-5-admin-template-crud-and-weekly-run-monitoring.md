# Story 23.5: Admin - Template CRUD & Weekly Run Monitoring

## Story

**As an** admin,
**I want** to create and edit weekly run templates using the existing game library and YAML editor, and monitor the current week's runs from the backoffice,
**So that** I can configure which games run each week with the same visual tool already used for events and personal runs.

## Status

ready

## Acceptance Criteria

**AC1:** `GET /api/v1/admin/weekly-templates` - `200 { data: [...], meta: { total } }`. Each entry: `id, name, gameId, gameName, maxAttempts, isActive, createdAt`. `gameName` joined from `game` table via DBAL (not stored on template).

**AC2:** `GET /api/v1/admin/weekly-templates/{id}` - `200 { data: { id, name, gameId, gameName, yamlConfig, maxAttempts, isActive } }`. Includes full `yamlConfig` for seeding the frontend YAML editor.

**AC3:** `POST /api/v1/admin/weekly-templates` with `{ gameId, yamlConfig, name?, maxAttempts? }`. Validates `gameId` exists and `apworld_storage_key IS NOT NULL` (DBAL query on `game` table - there is no `is_apworld_ready` column; readiness ≡ key present); returns `422 { error: 'game_not_ready' }` otherwise. Missing `gameId` or `yamlConfig` returns `422`. On success: `201 { data: { id, name, gameId, gameName, yamlConfig, maxAttempts, isActive } }`.

**AC4:** `PATCH /api/v1/admin/weekly-templates/{id}` with partial `{ name?, yamlConfig?, maxAttempts?, isActive? }`. `gameId` is immutable after creation (ignored if provided). Returns `200` with updated template. Unknown id returns `404`.

**AC5:** `DELETE /api/v1/admin/weekly-templates/{id}` - calls `WeeklyTemplate::deactivate()`, flushes. Returns `204`. Does not affect any `WeeklyRun` currently running. Unknown id returns `404`.

**AC6:** `GET /api/v1/admin/weekly-runs/current` - `200 { data: [...] }`. One object per run this ISO week (`status IN ('active', 'finished')`). Each object: `{ weeklyRunId, templateName, gameName, status, seed, startedAt, finishedAt, entryCount, entries: [{ userId, displayName, attemptNumber, externalSessionId, launchedAt, goalReachedAt, completionTimeSeconds, checksTotal, itemsTotal }] }`. All non-admin requests: `403`.

**AC7:** Admin frontend page `/admin/weekly-runs` with two sections: "Templates" table and "Run de la semaine" monitoring panel. Template creation is a two-step flow (game picker → YAML editor). Edit skips game selection (game is immutable). All three frontend quality gates pass.

## Tasks / Subtasks

- [ ] Task 1: Create `AdminWeeklyTemplateListQuery` (DBAL, with `gameName` join)
- [ ] Task 2: Create `AdminCreateWeeklyTemplate` service (validates `gameId` readiness, persists)
- [ ] Task 3: Create `AdminUpdateWeeklyTemplate` service (partial update, ignores `gameId`)
- [ ] Task 4: Create `AdminDeactivateWeeklyTemplate` service (`deactivate()` + flush)
- [ ] Task 5: Create `AdminCurrentWeeklyRunsQuery` (DBAL with joins)
- [ ] Task 6: Create all five admin controllers under `WeeklyRuns/Presentation/Admin/`
- [ ] Task 7: Write functional tests for all five endpoints (auth, validation, happy path)
- [ ] Task 8: Create `src/features/admin/admin-weekly-runs-api.ts` (fetch functions + type guards)
- [ ] Task 9: Create admin page `app/(admin)/admin/weekly-runs/page.tsx` + `AdminWeeklyRunsDashboard` component
- [ ] Task 10: Implement two-step template creation: `GamePickerStep` + `YamlConfigStep` (reuses `yaml-option-editor.tsx`)
- [ ] Task 11: Add "Weekly Runs" entry to the admin sidebar navigation
- [ ] Task 12: Run all quality gates (API + frontend)

## Dev Notes

### gameId readiness check (AC3)

On `POST`, validate before persisting. The `game` table has no `is_apworld_ready` column - readiness is determined by `apworld_storage_key IS NOT NULL` (same logic as `Game::isApworldReady()` in the domain):

```php
$row = $this->connection->createQueryBuilder()
    ->select('g.apworld_storage_key AS apworldStorageKey')
    ->from('game', 'g')
    ->where('g.id = :gameId')
    ->setParameter('gameId', $gameId)
    ->executeQuery()
    ->fetchAssociative();

if (false === $row) {
    // game not found → 422 game_not_ready
}
if (null === $row['apworldStorageKey']) {
    // game found but apworld not uploaded yet → 422 game_not_ready
}
```

### gameName join in list queries

```php
$qb
  ->select('wt.id', 'wt.name', 'wt.game_id AS gameId', 'g.name AS gameName',
           'wt.max_attempts AS maxAttempts', 'wt.is_active AS isActive', 'wt.created_at AS createdAt')
  ->from('weekly_templates', 'wt')
  ->leftJoin('wt', 'game', 'g', 'g.id = wt.game_id')
  ->orderBy('wt.created_at', 'DESC');
```

Use `LEFT JOIN` (not INNER) in case the referenced game was deleted - the template should still appear in admin views even with a dangling reference.

### Frontend: two-step template creation

The create flow lives in `AdminWeeklyTemplateCreatePage` (or a full-screen dialog). State machine:

```tsx
type Step = 'game-picker' | 'yaml-config';
const [step, setStep] = useState<Step>('game-picker');
const [selectedGame, setSelectedGame] = useState<AdminGame | null>(null);
```

**Step 1 - Game picker:**
- Fetches `GET /api/v1/admin/games?apworldReady=true` (existing endpoint - check if the `apworldReady` filter exists; if not, filter client-side)
- Renders a card grid: game cover image, game name
- Clicking a card sets `selectedGame` and advances to step 2

**Step 2 - YAML config:**
- Fetches `GET /api/v1/admin/games/{selectedGame.id}` to get `defaultYaml`
- Mounts `yaml-option-editor.tsx` with `defaultYaml` as the base schema (same props as in event/personal run flows)
- Below the editor: name input (optional), maxAttempts input with "Illimité" toggle (`null` when toggled)
- "Créer" button: serialises editor state via `serializeToYaml()`, calls `createAdminWeeklyTemplate()`

**Step 2 - Edit mode:**
- Skips step 1 (no `GamePickerStep` rendered)
- Fetches `GET /api/v1/admin/weekly-templates/{id}` to get `yamlConfig`
- Fetches `GET /api/v1/admin/games/{gameId}` to get `defaultYaml`
- Merges via `mergePlayerValues(defaultYaml, storedYamlConfig)` from `src/lib/archipelago-yaml.ts` to restore previous admin choices
- "Enregistrer" button: PATCHes the endpoint

### yaml-option-editor.tsx - NOT usable directly

`YamlOptionEditor` (`frontend/src/features/events/yaml-option-editor.tsx`) has registration/slot-specific required props (`registrationId: string`, `registrationOpen: boolean`, `slotId: string`, `onDirty: (slotId) => void`, `onSaved: (slotId) => void`) and its own save logic (tied to `saveUrl`). None of these apply to admin template creation.

**Do not try to reuse `YamlOptionEditor` directly.** Instead, build a simpler `AdminYamlEditor` component in `frontend/src/features/admin/admin-yaml-editor.tsx` using the same utility functions from `src/lib/archipelago-yaml.ts`:

```tsx
import { parseDefaultYaml, mergePlayerValues, serializeToYaml } from '@/lib/archipelago-yaml';

type Props = {
  defaultYaml: string;
  initialYaml?: string | null; // pre-populated in edit mode
  onChange: (yaml: string) => void;
};

export function AdminYamlEditor({ defaultYaml, initialYaml, onChange }: Props) {
  const [parsed, setParsed] = useState(() => {
    const base = parseDefaultYaml(defaultYaml);
    if (!base || !initialYaml) return base;
    const saved = parseDefaultYaml(initialYaml);
    return saved ? mergePlayerValues(base, saved) : base;
  });
  // ... render options, on change: onChange(serializeToYaml(newParsed, 'WeeklyTemplate'))
}
```

The parent stores the YAML string for submission. `AdminYamlEditor` has no save URL or slot ID - it is a controlled component via `onChange`. Copy the visual rendering logic from `YamlOptionEditor` (same option types, same categories) but strip all the registration/slot coupling.

### Frontend: "Run de la semaine" panel

Fetches `GET /api/v1/admin/weekly-runs/current` with TanStack Query (`staleTime: 30_000` - auto-refreshes every 30s). Renders one card per run:
- Status badge: `active` → green "En cours", `finished` → muted "Terminé"
- Seed pill: `archilan-weekly-YYYY-WW`
- Participant count + expandable table with per-entry metrics (externalSessionId truncated, launchedAt, goalReachedAt, completionTimeSeconds)
- No edit actions - monitoring only

### Admin sidebar

Add "Runs hebdo" entry to the `navItems` array in `frontend/src/components/admin-shell.tsx` pointing to `/admin/weekly-runs`, alongside the existing "Adhésions", "Jeux", etc. entries.

### admin-weekly-runs-api.ts types

```ts
export type AdminWeeklyTemplate = {
  id: string;
  name: string | null;
  gameId: string;
  gameName: string;
  yamlConfig?: string; // only on GET /{id}
  maxAttempts: number | null;
  isActive: boolean;
  createdAt: string;
};

export type AdminWeeklyRunEntry = {
  userId: string;
  displayName: string;
  attemptNumber: number;
  externalSessionId: string | null;
  launchedAt: string | null;
  goalReachedAt: string | null;
  completionTimeSeconds: number | null;
  checksTotal: number | null;
  itemsTotal: number | null;
};

export type AdminCurrentWeeklyRun = {
  weeklyRunId: string;
  templateName: string | null;
  gameName: string;
  status: 'active' | 'finished';
  seed: string;
  startedAt: string | null;
  finishedAt: string | null;
  entryCount: number;
  entries: AdminWeeklyRunEntry[];
};
```

All fetch functions follow the existing pattern: return typed result or `null`, catch errors, use type guards (`isAdminWeeklyTemplate`, etc.).

## File List

### API
- `api/src/WeeklyRuns/Application/AdminWeeklyTemplateListQuery.php` - new
- `api/src/WeeklyRuns/Application/AdminCreateWeeklyTemplate.php` - new
- `api/src/WeeklyRuns/Application/AdminUpdateWeeklyTemplate.php` - new
- `api/src/WeeklyRuns/Application/AdminDeactivateWeeklyTemplate.php` - new
- `api/src/WeeklyRuns/Application/AdminCurrentWeeklyRunsQuery.php` - new
- `api/src/WeeklyRuns/Presentation/Admin/AdminWeeklyTemplateListController.php` - new
- `api/src/WeeklyRuns/Presentation/Admin/AdminWeeklyTemplateDetailController.php` - new
- `api/src/WeeklyRuns/Presentation/Admin/AdminCreateWeeklyTemplateController.php` - new
- `api/src/WeeklyRuns/Presentation/Admin/AdminUpdateWeeklyTemplateController.php` - new
- `api/src/WeeklyRuns/Presentation/Admin/AdminDeactivateWeeklyTemplateController.php` - new
- `api/src/WeeklyRuns/Presentation/Admin/AdminCurrentWeeklyRunsController.php` - new
- `api/tests/Functional/AdminWeeklyTemplateTest.php` - new

### Frontend
- `frontend/src/features/admin/admin-weekly-runs-api.ts` - new
- `frontend/src/features/admin/admin-weekly-runs-dashboard.tsx` - new
- `frontend/src/features/admin/admin-yaml-editor.tsx` - new (controlled YAML editor, uses archipelago-yaml.ts utilities, no registration coupling)
- `frontend/src/features/admin/admin-weekly-template-form.tsx` - new (two-step creation/edit, uses AdminYamlEditor)
- `frontend/src/app/(admin)/admin/weekly-runs/page.tsx` - new
- `frontend/src/app/(admin)/admin/weekly-runs/nouveau/page.tsx` - new (create flow)
- `frontend/src/app/(admin)/admin/weekly-runs/[id]/modifier/page.tsx` - new (edit flow)
- `frontend/src/components/admin-shell.tsx` - modified (add "Runs hebdo" to `navItems`)

## Change Log

| Date       | Change                                                                                              |
|------------|-----------------------------------------------------------------------------------------------------|
| 2026-05-17 | Story created                                                                                       |
| 2026-05-17 | Revised: AC6 removes `pending` status (runs start as `active`) and `slotIndex` (per-player model). TS types updated to match. Sidebar file corrected to `components/admin-shell.tsx`. Status badge simplified. |
| 2026-05-17 | Revised: gameId readiness DBAL query corrected - no `is_apworld_ready` column; check `apworld_storage_key IS NOT NULL` instead. AC3 updated to match. |
| 2026-05-17 | Revised: YamlOptionEditor not usable directly (registration/slot-coupled). Story now specifies AdminYamlEditor wrapper component using archipelago-yaml.ts utilities. |
