# Story 9.11: Runner - Apworld Upload and Generation Pipeline

Status: review

## Story

As the session orchestration system,
I want the runner to accept .apworld file uploads, expose apworld-aware slot YAML writing, and pass stored apworld files to ArchipelagoGenerate,
so that apworld-ready sessions can be generated without relying on the legacy game-name + options pipeline.

## Acceptance Criteria

1. `POST /apworld/upload` accepts a multipart file upload, validates that the file has a `.apworld` extension, computes its SHA-256 hash, stores it at `{WORKSPACE_ROOT}/apworlds/{sha256}.apworld`, extracts `archipelago.json` from the ZIP archive to read `game`, runs `ArchipelagoGenerate --template` via subprocess to produce the default YAML, and returns `{ storageKey, hash, archipelagoGameName, defaultYaml }`.
2. If the uploaded file is not a `.apworld` archive or `archipelago.json` is missing, the endpoint returns HTTP 422 with a descriptive error.
3. If the subprocess times out (configurable via `APWORLD_TEMPLATE_TIMEOUT`, default 30 s), the endpoint returns HTTP 422.
4. `POST /sessions/{id}/yamls` accepts slots in both formats:
   - Legacy: `{ slotName, archipelagoGameName, options }` - behaviour unchanged.
   - Apworld: `{ slotName, apworldStorageKey, playerYaml }` - writes `playerYaml` content verbatim as the slot YAML file (no options merging).
5. `POST /sessions/{id}/preflight` validates apworld slots differently from legacy slots:
   - Apworld slot (`apworldStorageKey` present): validates that `playerYaml` is present and non-empty, and that `{WORKSPACE_ROOT}/apworlds/{apworldStorageKey}` exists.
   - Legacy slot (`archipelagoGameName` present): validates `archipelagoGameName` is non-empty, unchanged.
6. Before calling ArchipelagoGenerate, the generation pipeline copies every apworld file referenced by the session's slots into a session-scoped `apworlds/` subdirectory and passes that directory via `--world_directory` (flag name configurable via `ARCHIPELAGO_WORLD_DIR_FLAG`, default `--world_directory`).
7. All new and modified code paths are covered by pytest tests; no existing tests regress.

## Tasks / Subtasks

- [x] Add `python-multipart` dependency to `runner/pyproject.toml` (AC: 1)
  - [x] Add `python-multipart` under `[project].dependencies`

- [x] Create `runner/app/apworld_storage.py` (AC: 1, 2, 5, 6)
  - [x] `store(file_bytes, filename) -> str` - validate extension, compute sha256, persist to `{WORKSPACE_ROOT}/apworlds/{sha256}.apworld`, return storage key
  - [x] `path(storage_key) -> Path` - return absolute path; do not check existence
  - [x] `exists(storage_key) -> bool` - return whether the file is present on disk

- [x] Implement `POST /apworld/upload` in `runner/app/main.py` (AC: 1, 2, 3)
  - [x] Declare `UploadFile` parameter; reject if extension is not `.apworld` (422)
  - [x] Store file via `apworld_storage.store()`
  - [x] Open the stored file as a ZIP archive, read `archipelago.json`, extract `game` field (422 if absent)
  - [x] Run `{ARCHIPELAGO_GENERATE_CMD} --template --game {game} --output_path {tmp_dir}` with `asyncio.wait_for` using `APWORLD_TEMPLATE_TIMEOUT` (default 30 s); capture stdout; return 422 on timeout or non-zero exit
  - [x] Read generated template YAML from output directory, return `{ storageKey, hash, archipelagoGameName, defaultYaml }`
  - [x] Auth: require `X-Api-Key` header (same as all other endpoints)

- [x] Update `runner/app/yaml_writer.py` `write_slot_yamls()` (AC: 4)
  - [x] Detect slot type: if `apworldStorageKey` key present → apworld format; else → legacy format
  - [x] Apworld format: write `playerYaml` string directly as `{yamls_dir}/{slotName}.yaml`
  - [x] Legacy format: existing logic unchanged

- [x] Update `POST /sessions/{id}/yamls` handler in `runner/app/main.py` (AC: 4)
  - [x] Accept mixed slot list (each element either apworld or legacy format)
  - [x] Pass slots through to `write_slot_yamls()` without type coercion

- [x] Update `POST /sessions/{id}/preflight` handler in `runner/app/main.py` (AC: 5)
  - [x] For each slot, branch on `apworldStorageKey` presence vs `archipelagoGameName` presence
  - [x] Apworld validation: `playerYaml` non-empty AND `apworld_storage.exists(apworldStorageKey)`
  - [x] Legacy validation: `archipelagoGameName` non-empty (unchanged)
  - [x] Return 422 listing all failing slots

- [x] Update generation pipeline in `runner/app/generator.py` (AC: 6)
  - [x] Before `run_generation()`, collect unique `apworldStorageKey` values from session slots (read from session state or pass as argument)
  - [x] Copy each referenced `.apworld` file to `{session_dir}/apworlds/`
  - [x] Append `{ARCHIPELAGO_WORLD_DIR_FLAG} {session_dir}/apworlds` to the generate command when at least one apworld file was copied

- [x] Add `runner/tests/test_apworld_upload.py` (AC: 1, 2, 3, 7)
  - [x] Happy path: valid `.apworld` ZIP with `archipelago.json` → 200, correct storageKey/hash/archipelagoGameName/defaultYaml
  - [x] Invalid extension → 422
  - [x] Missing `archipelago.json` in ZIP → 422
  - [x] Subprocess timeout → 422
  - [x] No auth → 401

- [x] Update `runner/tests/test_yaml_writer.py` (AC: 4, 7)
  - [x] Apworld slot: `playerYaml` written verbatim, no options block
  - [x] Legacy slot: existing assertions unchanged
  - [x] Mixed slot list: both formats written correctly

- [x] Update `runner/tests/test_preflight.py` (AC: 5, 7)
  - [x] Apworld slot, file present + playerYaml non-empty → passes
  - [x] Apworld slot, file missing → 422
  - [x] Apworld slot, playerYaml empty → 422
  - [x] Legacy slot: existing assertions unchanged

- [x] Update `runner/tests/test_generation.py` (AC: 6, 7)
  - [x] Session with apworld slots: `--world_directory` flag appended, apworld files copied
  - [x] Session with legacy-only slots: no `--world_directory` flag, no copy

## Dev Notes

- The runner is a FastAPI (Python) service in `runner/`. Entry point: `runner/app/main.py`. Tests under `runner/tests/`.
- Auth pattern: every non-health endpoint validates `X-Api-Key` header against `RUNNER_API_KEY` env var (see existing handlers in `main.py`).
- Storage root: `WORKSPACE_ROOT` env var; apworlds live at `{WORKSPACE_ROOT}/apworlds/`. Create the directory if absent (same pattern as session workspace creation).
- Subprocess pattern for generation: `asyncio.create_subprocess_exec(…)` + `asyncio.wait_for(proc.communicate(), timeout)` - see `generator.py::run_generation()`.
- `ARCHIPELAGO_GENERATE_CMD` is the configured path/command for ArchipelagoGenerate; the `--template` flag produces a YAML template for a given game and does not require player files.
- The `--world_directory` flag (or whatever `ARCHIPELAGO_WORLD_DIR_FLAG` resolves to) tells ArchipelagoGenerate where to find custom world `.apworld` files. Only append it when at least one apworld is present to avoid breaking the vanilla case.
- `SessionOrchestrator::buildRunnerSlots()` in `api/src/Sessions/Application/SessionOrchestrator.php` already emits the dual format; the PHP side is complete. This story closes the runner-side gap.
- `SessionOrchestrator::buildPreflightSlotsForCreation()` still sends legacy format only - the PHP preflight path is a separate concern and does not need updating here.

### Project Structure Notes

- New file: `runner/app/apworld_storage.py`
- New test file: `runner/tests/test_apworld_upload.py`
- Modified: `runner/app/main.py`, `runner/app/yaml_writer.py`, `runner/app/generator.py`
- Modified: `runner/pyproject.toml` (add `python-multipart`)
- Modified test files: `runner/tests/test_yaml_writer.py`, `runner/tests/test_preflight.py`, `runner/tests/test_generation.py`

### References

- Runner entry point: `runner/app/main.py`
- Generation logic: `runner/app/generator.py`
- Slot YAML writing: `runner/app/yaml_writer.py`
- Test fixtures and client setup: `runner/tests/conftest.py`
- PHP dual-format slot builder: `api/src/Sessions/Application/SessionOrchestrator.php::buildRunnerSlots()`
- Runner gateway (PHP caller): `api/src/Sessions/Infrastructure/RunnerGateway.php::uploadApworld()`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- `apworld_storage.py` - store/path/exists helpers, idempotent par SHA-256.
- `yaml_writer.py` - dual format : apworld écrit `playerYaml` verbatim + écrit `apworld_keys.json` ; legacy inchangé.
- `generator.py` - lit `apworld_keys.json`, copie les `.apworld` dans `{session}/apworlds/`, ajoute `--world_directory` uniquement si présent. `world_dir_flag` configurable via kwarg (défaut `--world_directory`).
- `main.py` - `POST /apworld/upload` (multipart, ZIP validation, subprocess template, retour JSON) ; yamls handler : pass-through dual format sans coercion ; preflight : branche apworld vs legacy.
- 121/121 tests passent, aucune régression.

### File List

- `runner/pyproject.toml` (modifié - ajout `python-multipart`)
- `runner/app/apworld_storage.py` (nouveau)
- `runner/app/yaml_writer.py` (modifié - dual format + manifest)
- `runner/app/generator.py` (modifié - copie apworlds + flag world_directory)
- `runner/app/main.py` (modifié - endpoint upload + handlers mis à jour)
- `runner/tests/test_apworld_upload.py` (nouveau)
- `runner/tests/test_yaml_writer.py` (modifié - cas apworld ajoutés)
- `runner/tests/test_preflight.py` (modifié - cas apworld ajoutés)
- `runner/tests/test_generation.py` (modifié - cas apworld pipeline ajoutés)
