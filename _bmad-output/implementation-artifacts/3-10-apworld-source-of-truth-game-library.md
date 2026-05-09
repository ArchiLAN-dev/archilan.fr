# Story 3.10: .apworld as Source of Truth for Game Library

Status: done

## Story

As an admin,
I want to configure a game in the library by uploading its `.apworld` file,
so that the Archipelago YAML option template is extracted automatically, version-snapshotted at registration time, and used directly by the runner for session generation - without manually defining options or YAML templates.

## Background: How Archipelago YAML Extraction Works

This section is **mandatory reading before implementation**. It directly shapes the runner contract and the frontend UX.

### What is a `.apworld` file?

A `.apworld` is a **ZIP archive** containing a Python package - the Archipelago world module for one game:
```
hollow_knight.apworld (ZIP)
└── hollow_knight/
    ├── __init__.py      ← exports World subclass with .game = "Hollow Knight"
    ├── options.py       ← Options dataclass
    ├── archipelago.json ← { "game": "Hollow Knight" }
    └── ...
```
The `World` class has `game: str` (e.g. `"Hollow Knight"`) - this is `archipelagoGameName`.

### YAML template extraction

`Options.py` provides `generate_yaml_templates(output_dir, skip_images=True)`. This function:
- Writes **one file per registered world** to `output_dir`: `"{game_name}.yaml"` (spaces preserved in filename).
- **Writes to disk** - does not return a string.
- Generates templates for ALL currently loaded worlds, not just one.
- Has **no dedicated CLI** for a single `.apworld` (Archipelago Issue #4796, open).

Worlds are auto-registered via the `AutoWorldRegister` metaclass when their Python module is imported.

### Subprocess isolation (mandatory for the runner)

A long-running FastAPI service cannot dynamically import a new `.apworld` into its own process: `sys.modules` contamination cannot be cleanly undone. Each upload must spawn a **subprocess** that:
1. Starts with a clean Python environment.
2. Identifies the game name from the ZIP manifest (`archipelago.json` → `game` field) **before** importing anything.
3. Copies the `.apworld` to a temp `worlds/` dir, imports Archipelago (triggers auto-registration).
4. Calls `generate_yaml_templates(tmpdir)`, reads `{game_name}.yaml`.
5. Outputs `{ "game_name": "...", "yaml_template": "..." }` on stdout and exits.

The subprocess identifies the game name from the ZIP directly (not by comparing before/after registries - that approach breaks if the runner already has the same game installed).

### YAML template format and player customization

The template uses Archipelago's **weight-based system**:
```yaml
name: PlayerName
game: Hollow Knight

Hollow Knight:
  RandomizeDreamers:
    true: 50
    false: 50
  StartingGeo:
    random: 50
    random-low: 25
    random-high: 25
  MaximumGrubs: 46
```

Players have three degrees of freedom over any option:
1. **Force a value** (scalar): `RandomizeDreamers: true` / `MaximumGrubs: 23`
2. **Adjust existing weights**: `RandomizeDreamers:\n  true: 100\n  false: 0`
3. **Add their own weight entries** - key for `Range` options not shown in the template:
   ```yaml
   MaximumGrubs:
     23: 1    # specific values with custom weights
     46: 2
   ```
   For `Choice` options, added keys must be valid declared values. For `Range` options, any integer within the declared range is valid.

The `playerYaml` string is stored verbatim and passed to the runner unchanged. Archipelago validates it at generation time - not at registration time. YAML errors surface when the admin clicks "Generate".

## Acceptance Criteria

1. Given an admin opens a game's detail page, they can upload a `.apworld` file; the system sends it to the runner; the runner extracts `archipelagoGameName` and the full YAML template in a subprocess, stores the `.apworld`, and returns `{ storageKey, hash, archipelagoGameName, defaultYaml }`.
2. The game record stores: `apworldStorageKey`, `apworldHash` (SHA-256 hex), `apworldUploadedAt`, `defaultYaml`. The individual game API response includes these fields and `isApworldReady`. The game list response does **not** include `defaultYaml` (too heavy).
3. Uploading a new `.apworld` to an existing game updates all four fields; the `apworldHash` of existing registration slots is preserved (version snapshot).
4. When a player saves their game slot configuration for an `.apworld`-ready game, the slot stores `apworldHash` (server-side lookup of the current game hash) and `playerYaml` (initialized from `game.defaultYaml`, player-editable). For legacy games, the existing options system is unchanged.
5. When the admin triggers session generation, the runner receives `{ slotName, apworldStorageKey, playerYaml }` for `.apworld`-ready slots; the runner uses the stored `.apworld` + the player's YAML to generate the seed.
6. Legacy games (no `.apworld`) continue using `{ slotName, archipelagoGameName, options }` - no regression.
7. Only ROLE_ADMIN can call `PATCH /api/v1/admin/games/{gameId}/apworld`; anonymous → 401, lambda → 403.

## Tasks / Subtasks

- [ ] **Runner: implement `POST /apworld/upload`** (AC: 1)
  - [ ] Endpoint on the runner (Python/FastAPI, `RUNNER_BASE_URL` env var).
  - [ ] Auth: `x-api-key` header, same as existing endpoints.
  - [ ] Request: `multipart/form-data`, field `file` = `.apworld` bytes.
  - [ ] Implementation:
    1. Read bytes; compute SHA-256: `hashlib.sha256(contents).hexdigest()`.
    2. Store `.apworld` permanently; `storageKey` = opaque path/key used later by `writeYamls`.
    3. Read game name from ZIP manifest: `zipfile.ZipFile(io.BytesIO(contents))` → find `archipelago.json` in any subdirectory → parse `{ "game": "..." }`. Use this as ground truth for `game_name`, not the filename.
    4. Spawn extraction subprocess with 60s timeout (see Dev Notes for script). Subprocess identifies game via manifest, copies to temp `worlds/`, calls `generate_yaml_templates`, returns JSON on stdout.
    5. On non-zero exit or parse failure: return 422 `{ error: "extraction_failed", message: stderr }`.
  - [ ] Response 200: `{ storageKey: string, hash: string, archipelagoGameName: string, defaultYaml: string }`.
  - [ ] Response 422: `{ error: "extraction_failed", message: string }`.

- [ ] **Backend: ArchipelagoGame entity - new columns** (AC: 2, 3)
  - [ ] Add to `api/src/GameSelection/Domain/ArchipelagoGame.php`:
    ```php
    #[ORM\Column(name: 'apworld_storage_key', type: 'string', length: 500, nullable: true)]
    private ?string $apworldStorageKey = null;

    #[ORM\Column(name: 'apworld_hash', type: 'string', length: 64, nullable: true)]
    private ?string $apworldHash = null;

    #[ORM\Column(name: 'apworld_uploaded_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $apworldUploadedAt = null;

    #[ORM\Column(name: 'default_yaml', type: 'text', nullable: true)]
    private ?string $defaultYaml = null;
    ```
  - [ ] Add `configureApworld(string $storageKey, string $hash, string $archipelagoGameName, string $defaultYaml, \DateTimeImmutable $now): void` - sets all four new fields, also sets `$this->archipelagoGameName = $archipelagoGameName`, updates `updatedAt`.
  - [ ] Add `isApworldReady(): bool` → `null !== $this->apworldStorageKey`.
  - [ ] Add getters: `getApworldStorageKey()`, `getApworldHash()`, `getApworldUploadedAt()`, `getDefaultYaml()`.
  - [ ] Keep `randomizerOptions`, `optionSchemaVersion`, `defaultYamlValues`, `isYamlReady()` - do NOT remove (legacy compat, AC: 6).
  - [ ] Constructor: add four nullable params with `= null` defaults after existing `updatedAt`.
  - [ ] Migration `api/migrations/Version{timestamp}.php` (namespace `DoctrineMigrations`, extends `AbstractMigration`):
    ```php
    $this->addSql('ALTER TABLE game_selection_games ADD apworld_storage_key VARCHAR(500) DEFAULT NULL, ADD apworld_hash VARCHAR(64) DEFAULT NULL, ADD apworld_uploaded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD default_yaml TEXT DEFAULT NULL');
    ```

- [ ] **Backend: RunnerGatewayInterface + implementations** (AC: 1)
  - [ ] Add to `api/src/Sessions/Infrastructure/RunnerGatewayInterface.php`:
    ```php
    /** @return array{storageKey: string, hash: string, archipelagoGameName: string, defaultYaml: string}|array{error: string} */
    public function uploadApworld(string $fileContents, string $filename): array;
    ```
  - [ ] Implement in `RunnerGateway.php` using `symfony/mime` multipart with **90s timeout** (see Dev Notes).
  - [ ] Stub in `NullRunnerGateway.php`: return `['error' => 'runner_unavailable']`.

- [ ] **Backend: AdminGameLibrary - `configureApworld`** (AC: 1, 2, 3)
  - [ ] Add `configureApworld(string $gameId, string $fileContents, string $filename): array` with return type `array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}`.
  - [ ] Steps:
    1. Find game - `['found' => false, 'errors' => []]` if missing.
    2. Validate extension: `pathinfo($filename, PATHINFO_EXTENSION) === 'apworld'` - error `file: "Le fichier doit avoir l'extension .apworld."`.
    3. Validate non-empty.
    4. Call `$this->runnerGateway->uploadApworld($fileContents, $filename)`.
    5. On `error` key: `['found' => true, 'errors' => ['file' => ['Le runner est indisponible ou le fichier .apworld est invalide.']]]`.
    6. Validate all four returned fields are non-empty strings.
    7. Call `$game->configureApworld(...)`, flush, log `'game.apworld_configured'`.
    8. Return `['found' => true, 'game' => $this->payload($game), 'errors' => []]`.
  - [ ] Inject `RunnerGatewayInterface` into constructor (autowires - verify: `php bin/console debug:autowiring RunnerGatewayInterface`).
  - [ ] Update `payload()` (used by both list AND detail): add `'apworldHash'`, `'apworldUploadedAt'`, `'isApworldReady'`. Do **NOT** add `'defaultYaml'` here.
  - [ ] Add a separate `detailPayload()` method (or equivalent) that extends `payload()` with `'defaultYaml'`. The detail endpoint (`GET /admin/games/{gameId}`) uses `detailPayload()`; the list endpoint (`GET /admin/games`) uses `payload()`.

- [ ] **Backend: API endpoint `PATCH /api/v1/admin/games/{gameId}/apworld`** (AC: 1, 7)
  - [ ] Add to `api/src/GameSelection/Presentation/AdminGameLibraryController.php`:
    ```php
    #[Route('/api/v1/admin/games/{gameId}/apworld', name: 'api_admin_game_configure_apworld', methods: ['PATCH'])]
    public function configureApworld(string $gameId, Request $request): JsonResponse
    ```
  - [ ] Auth guard FIRST. Read `$request->files->get('file')` as `UploadedFile` - 422 if missing. Read contents with `file_get_contents($file->getPathname())`. Delegate to `adminGameLibrary->configureApworld()`. Map: 404 / 422 / 200 `{ data: detailPayload }`. Do NOT call `$request->toArray()`.

- [ ] **Backend: RBAC enforcement** (AC: 7)
  - [ ] Add to `adminRequests()` in `api/tests/Functional/RbacEnforcementTest.php`:
    ```php
    ['method' => 'PATCH', 'path' => '/api/v1/admin/games/nonexistent/apworld'],
    ```

- [ ] **Backend: AdminGameApworldTest functional test** (AC: 1, 7)
  - [ ] Create `api/tests/Functional/AdminGameApworldTest.php`.
  - [ ] Test: anonymous → 401, lambda → 403, admin + non-existent game → 404.
  - [ ] Happy path: `NullRunnerGateway` returns `error` in test env. Check existing session tests for runner mock pattern. If a `FakeRunnerGateway` mechanism exists, use it; otherwise test success logic in a unit test of `AdminGameLibrary::configureApworld()`.

- [ ] **Backend: Registration slot schema** (AC: 4)
  - [ ] In `api/src/Registrations/Domain/Registration.php`, extend `gameSlots` PHPDoc to include `apworldHash: string|null` and `playerYaml: string|null` per slot.
  - [ ] Update `replaceSlots()` input type and body to include both fields.
  - [ ] Add `setSlotPlayerYaml(string $slotId, string $playerYaml, \DateTimeImmutable $now): void` - parallel to existing `setSlotOptions()`, finds slot by `slotId`, sets `slot['playerYaml']`, updates `updatedAt`. Throws `\DomainException` if slot missing or registration not reserved.
  - [ ] No DB migration: `game_slots` is JSON; existing rows return `null` for missing keys.

- [ ] **Backend: New endpoint `PUT /api/v1/registrations/{id}/slots/{slotId}/yaml`** (AC: 4)
  - [ ] Add to `api/src/Registrations/Presentation/RegistrationController.php`:
    ```php
    #[Route('/api/v1/registrations/{registrationId}/slots/{slotId}/yaml', name: 'api_registrations_slot_yaml_put', methods: ['PUT'])]
    ```
  - [ ] Auth: `requireUser`. Parse JSON body: `playerYaml: string`. Validate non-empty string.
  - [ ] In `api/src/Registrations/Application/RegistrationGameSelection.php`, add `saveSlotYaml(string $registrationId, string $userId, string $slotId, string $playerYaml): ?array`:
    1. Find registration - null if not found, not owned by user, or not reserved.
    2. Find event - null if not found.
    3. Check registration window not closed.
    4. Check game selection enabled.
    5. Find slot by `slotId` - null if not found.
    6. Find game: `$game = $this->entityManager->find(ArchipelagoGame::class, $slot['gameId'])`.
    7. Validate game `isApworldReady()` - error if not (legacy game, use options endpoint instead).
    8. Call `$registration->setSlotPlayerYaml($slotId, $playerYaml, new \DateTimeImmutable())`. This also stores `apworldHash` via: first update the slot's `apworldHash` to `$game->getApworldHash()` (either inside `setSlotPlayerYaml` or via a separate `setSlotApworldHash` call before it - keep atomic).
    9. Flush + log.
    10. Return `['outcome' => 'ok']`.
  - [ ] Add `PUT /registrations/{id}/slots/{slotId}/yaml` to `protectedRequests()` in `RbacEnforcementTest.php` (anonymous → 401).

- [ ] **Backend: `getSelection` response - expose `isApworldReady` and `defaultYaml`** (AC: 4)
  - [ ] In `RegistrationGameSelection::buildAvailableGames()`, add `'isApworldReady'` and `'defaultYaml'` to the per-game response. These are needed by the frontend to decide whether to show the YAML editor or the legacy options panel for each game.
  - [ ] In `buildSlotsWithOptions()`, add `'playerYaml'` and `'apworldHash'` to each slot in the response - needed for editing an existing slot's YAML.

- [ ] **Backend: Session generation - dual pipeline** (AC: 5, 6)
  - [ ] In `api/src/Sessions/Application/SessionOrchestrator.php`, `buildRunnerSlots()`:
    ```php
    if ($game?->isApworldReady() && isset($regSlot['playerYaml']) && null !== $regSlot['playerYaml']) {
        $result[] = ['slotName' => $slot->getSlotName(), 'apworldStorageKey' => $game->getApworldStorageKey(), 'playerYaml' => $regSlot['playerYaml']];
    } else {
        // legacy - unchanged
        $result[] = ['slotName' => $slot->getSlotName(), 'archipelagoGameName' => $game?->getArchipelagoGameName() ?? '', 'options' => array_merge($defaultOptions, $regOptions)];
    }
    ```
  - [ ] Add hash mismatch warning log if `$regSlot['apworldHash'] ?? null` differs from `$game->getApworldHash()`.
  - [ ] Update `RunnerGatewayInterface::writeYamls()` PHPDoc: slots can be either format. Signature unchanged.

- [ ] **Frontend prerequisite: admin game detail page** (AC: 1, 2)
  - [ ] Currently `/admin/jeux` has only a list page (`page.tsx`). A `[gameId]` detail/edit page does not exist yet - **this must be created as part of this story**.
  - [ ] Create `frontend/src/app/(admin)/admin/jeux/[gameId]/page.tsx` - admin game edit page.
  - [ ] Minimal scope for this story: the page fetches `GET /api/v1/admin/games/{gameId}` and renders the `.apworld` upload section (see below). Existing list-level editing (name, description, options) can be left on the list page or migrated - scope is flexible, but the new `.apworld` section must live on a detail page.

- [ ] **Frontend: Admin game detail - `.apworld` upload section** (AC: 1, 2)
  - [ ] In the new `/admin/jeux/[gameId]` page:
    - If `game.isApworldReady === false`: status "Aucun fichier .apworld configuré".
    - If ready: "Configuré le [apworldUploadedAt localized] - SHA-256: [hash.slice(0,8)]...".
    - File input `accept=".apworld"`. Upload button disabled until file selected.
    - On submit: raw `fetch` with `FormData`, `method: 'PATCH'`, `credentials: 'include'` (see Dev Notes - no JSON helper).
    - On success: refresh game data, show `defaultYaml` in a read-only scrollable `<pre>`.
    - On error: inline field error.
  - [ ] Keep legacy "Options" and "Template YAML" sections for games that don't have `.apworld`.

- [ ] **Frontend: Player registration - YAML editor** (AC: 4)
  - [ ] In the game-selection step (`/evenements/[eventSlug]/inscription/[registrationId]/jeux`):
    - For each selected slot, after game selection is saved, show per-slot configuration:
      - If `game.isApworldReady === true`: `<textarea>` pre-filled with existing `slot.playerYaml || game.defaultYaml`. Label: "Configuration YAML pour [gameName]". Helper: "Modifiez les poids ou les valeurs. Les nombres sont des probabilités de tirage - vous pouvez aussi ajouter vos propres valeurs pour les options de type plage (Range)." Save button calls `PUT /registrations/{id}/slots/{slotId}/yaml` with `{ playerYaml: string }`.
      - If `game.isApworldReady === false`: show existing `GameOptionPanel` - unchanged.

- [ ] **Validate and handoff**
  - [ ] `php bin/console doctrine:migrations:migrate` - migration applied cleanly.
  - [ ] `composer test` - all new tests pass, no regressions.
  - [ ] `composer phpstan` - 0 new errors on changed files.
  - [ ] `composer cs-fixer` - clean.
  - [ ] `pnpm typecheck` + `pnpm build` - no errors.

## Dev Notes

### Multipart in Symfony Controller

```php
use Symfony\Component\HttpFoundation\File\UploadedFile;
$file = $request->files->get('file');
if (!$file instanceof UploadedFile) { /* return 422 */ }
$contents = file_get_contents($file->getPathname()); // guard against false
```
Do NOT call `$request->toArray()` on a multipart request.

### Multipart + 90s timeout in RunnerGateway

```php
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

$formData = new FormDataPart(['file' => new DataPart($fileContents, $filename, 'application/octet-stream')]);
$response = $this->httpClient->request('POST', $this->url('/apworld/upload'), [
    'headers' => array_merge(['x-api-key' => $this->runnerApiKey], $formData->getPreparedHeaders()->toArray()),
    'body' => $formData->bodyToString(),
    'timeout' => 90, // subprocess can take up to 60s
]);
```
Do NOT set `Content-Type` manually - `getPreparedHeaders()` provides the correct boundary.

### Frontend: Raw fetch for multipart

```ts
const formData = new FormData();
formData.append('file', selectedFile);
const res = await fetch(`${env.apiBaseUrl}/admin/games/${gameId}/apworld`, {
  method: 'PATCH',
  body: formData, // browser sets Content-Type + boundary automatically
  credentials: 'include',
});
```
Do NOT use any helper that calls `JSON.stringify` or sets `Content-Type: application/json`.

### Runner: Game name from ZIP manifest (not filename)

Before spawning the subprocess, read the game name from the ZIP directly:
```python
import zipfile, json, io

with zipfile.ZipFile(io.BytesIO(contents)) as zf:
    manifest_names = [n for n in zf.namelist() if n.endswith('archipelago.json')]
    if not manifest_names:
        raise ValueError("No archipelago.json found in .apworld")
    manifest = json.loads(zf.read(manifest_names[0]))
    game_name = manifest["game"]  # e.g. "Hollow Knight"
```
This is the authoritative source - more reliable than the `.apworld` filename and avoids the before/after registry comparison trick (which breaks if the game is already installed in the runner's worlds/).

### Runner: Subprocess extraction script

```python
# extract_apworld.py - run as subprocess
import sys, os, shutil, json, tempfile

apworld_path = sys.argv[1]   # absolute path to stored .apworld
game_name    = sys.argv[2]   # already extracted from manifest by the caller

sys.path.insert(0, os.environ["ARCHIPELAGO_ROOT"])

with tempfile.TemporaryDirectory() as tmpdir:
    worlds_dir = os.path.join(tmpdir, "worlds")
    os.makedirs(worlds_dir)
    shutil.copy(apworld_path, os.path.join(worlds_dir, os.path.basename(apworld_path)))

    # zipimport is the reliable way to load a .apworld without an ARCHIPELAGO_WORLDS_FOLDER env var
    import zipimport
    world_slug = os.path.basename(apworld_path).replace('.apworld', '')
    importer = zipimport.zipimporter(os.path.join(worlds_dir, os.path.basename(apworld_path)))
    importer.load_module(world_slug)  # triggers AutoWorldRegister metaclass

    from Options import generate_yaml_templates
    out_dir = os.path.join(tmpdir, "templates")
    os.makedirs(out_dir)
    generate_yaml_templates(out_dir, True)  # True = skip_open_folder

    template_path = os.path.join(out_dir, f"{game_name}.yaml")
    if not os.path.exists(template_path):
        print(json.dumps({"error": "template_not_generated"})); sys.exit(1)

    with open(template_path) as f:
        yaml_content = f.read()

print(json.dumps({"yaml_template": yaml_content}))
```
The `game_name` is passed in from the manifest-reading step rather than discovered in the subprocess, avoiding the registry comparison problem.

### `generate_yaml_templates` second parameter

The Launcher calls: `generate_yaml_templates(target, False)` where `False` = don't skip opening the folder (i.e., open it for the user). In the runner subprocess, pass `True` to skip opening any folder. The parameter is positional, not a keyword arg - write it as `generate_yaml_templates(out_dir, True)`.

### `detailPayload()` vs `payload()` in AdminGameLibrary

`payload()` is called by both `list()` and the individual-game endpoints. `defaultYaml` can be multiple KBs. Do not put it in `payload()` since `list()` returns all games at once. Options:
- A) Add a `detailPayload(ArchipelagoGame $game): array` method that calls `$this->payload($game)` and merges `'defaultYaml'`.
- B) Add a `$includeYaml = false` parameter to `payload()`.
Option A is cleaner - use it.

### `setSlotPlayerYaml` atomicity with `apworldHash`

The slot must store both `playerYaml` AND `apworldHash` together (atomicity). Two approaches:
- A) `setSlotPlayerYaml(slotId, playerYaml, apworldHash, now)` - sets both fields in one call.
- B) Two separate calls in the service before flush.

Use **Option A** - one domain method, both fields set atomically.

### Existing `RegistrationGameSelection::buildAvailableGames()` and `buildSlotsWithOptions()`

`buildAvailableGames()` already does a batch load of game entities (`WHERE g.id IN (:ids)`) - extend it to include `isApworldReady` and `defaultYaml` per game in the response. No N+1 risk.

`buildSlotsWithOptions()` already loads games in batch - extend it to include `playerYaml` and `apworldHash` from `$slot['playerYaml']` and `$slot['apworldHash']` per slot in the response.

### Edge case: admin updates `.apworld` while a player has an open registration

If a player has selected a game but not yet saved their YAML, and the admin uploads a new `.apworld` in the meantime, the player will see the NEW `defaultYaml` pre-filled (since `defaultYaml` comes from the current game record at `getSelection` time). This is acceptable - the player hasn't committed a YAML yet. Once the player saves their YAML (via the new endpoint), the `apworldHash` at that moment is snapshotted. This is the correct behavior and requires no special handling.

### Backward compatibility in `buildRunnerSlots`

Both conditions must be true for the new pipeline:
1. `$game?->isApworldReady()` - game has `.apworld`
2. `$regSlot['playerYaml'] ?? null` is not null - slot has a saved YAML

A slot from before this story has `playerYaml = null`, so it falls back to the legacy pipeline even if the game is later updated to have `.apworld`. This handles the migration window gracefully.

### AC3: hash snapshot scope

The `apworldHash` is stored at slot save time. In this story, if the hash in a slot differs from the current `game.apworldHash` at session generation time, the runner still uses the current `apworldStorageKey` (with a warning log). Full per-hash `.apworld` routing is post-MVP scope. The hash is stored now so future stories can implement it without a schema change.
