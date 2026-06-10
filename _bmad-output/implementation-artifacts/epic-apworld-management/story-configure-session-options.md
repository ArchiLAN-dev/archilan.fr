# Story - Configure Session from Structured Options

**Epic:** Apworld Management  
**Story ID:** epic-apworld-management / story-configure-session-options

---

## Context

`POST /sessions/{sessionId}/configure` currently accepts raw Archipelago YAML per slot
(`playerYaml` string). Callers must build the YAML themselves, including the game name
and the correct section structure.

After the introspect-option-types story, the orchestrateur knows the canonical game name
for each apworld (stored as `{hash}.json`). The API can now offer an alternative input
mode: the caller sends a structured payload (`playerName` + `values` map) and the server
generates the YAML - removing the dependency on the client knowing the YAML format.

---

## Solution: server-side YAML generation (Option B)

`ConfigureSlotEntry` gains an optional `options` field alongside the existing `playerYaml`.
When `options` is present, the server:
1. Looks up the game name from `{hash}.json` in Minio
2. Generates the Archipelago YAML using `gopkg.in/yaml.v2`
3. Uses that YAML for validation and upload - same storage path as before

Both modes coexist; `options` takes priority when set.

The PHP client gains a `SlotOptions` value object and `ConfigureSlot::fromOptions()` factory.

---

## Acceptance Criteria

- [ ] `POST /sessions/{id}/configure` accepts `{"slots":[{"apworldHash":"...","options":{"playerName":"Jean","values":{...}}}]}`
- [ ] Server resolves game name from `{hash}.json` and generates valid Archipelago YAML
- [ ] Weights values (`map[string]int`) serialise correctly as nested YAML maps
- [ ] Missing `playerName` in options mode returns a validation error
- [ ] Unknown apworld hash returns the same validation error as YAML mode
- [ ] Existing raw-YAML mode (`playerYaml` field) is unchanged
- [ ] `SlotOptions` PHP value object is serialisable to the options payload
- [ ] `ConfigureSlot::fromOptions(hash, SlotOptions)` factory produces the correct JSON
- [ ] `ConfigureSlot::fromYaml(hash, PlayerYaml)` factory preserves existing behaviour
- [ ] All quality gates pass (PHPStan, PHP-CS-Fixer, PHPUnit, go build)

---

## Tasks

- [x] Write BMAD story (this file)
- [x] `orchestrateur/internal/api/types.go` - add `SlotOptionsPayload`, extend `ConfigureSlotEntry`
- [x] `orchestrateur/internal/storage/client.go` - add `GetApworldMeta(ctx, hash)`
- [x] `orchestrateur/internal/service/session.go` - add `SlotOptionsPayload`, `buildPlayerYaml()`, update `ConfigureSession()`
- [x] `orchestrateur/internal/api/session_handlers.go` - pass `Options` through to service layer
- [x] `packages/orchestrateur-client/src/Sessions/Request/SlotOptions.php` - new value object
- [x] `packages/orchestrateur-client/src/Sessions/Request/ConfigureSlot.php` - private ctor + `fromYaml()` / `fromOptions()` factories
- [x] Update `SessionsClientTest.php` and `test.php` to use `ConfigureSlot::fromYaml()`

## Status: DONE

All quality gates pass: PHPStan level 9 (0 errors), PHPUnit 53/53, go build clean.
