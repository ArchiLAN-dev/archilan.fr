# Story - Introspect Apworld Option Types via Python

**Epic:** Apworld Management  
**Story ID:** epic-apworld-management / story-introspect-option-types

---

## Context

The YAML template parser (`templateparser`) infers option types (range/choice/toggle/text) from the
template content alone. This works well for most cases but cannot distinguish between options that
are truly "choice" (pick one) and "weights" (each value gets an independent numeric weight 0–100),
because both appear identically in the generated YAML.

The only authoritative source of this information is the Python class hierarchy inside the apworld
itself. `OptionDict` subclasses are weight maps; `Choice` subclasses are single-pick selects.

## Solution: Python introspection at upload time (Option B)

At apworld upload time, run a second one-shot Archipelago container that imports the world class,
inspects `options_dataclass.__dataclass_fields__`, walks the Python MRO, and outputs a JSON map of
`{optionKey → {type, defaultWeights?}}`. This result is stored as `{hash}.types.json` in Minio and
merged into the YAML-parsed options when `GET /apworlds/{hash}/options` is called.

The introspection runs asynchronously (goroutine) so it does not extend the upload response time.

---

## Acceptance Criteria

- [ ] `archipelago/introspect_options.py` classifies each option field as range/choice/toggle/text/weights
- [ ] For `OptionDict` options, the script also includes `defaultWeights: {key: int}` in the output
- [ ] The script shares the same bootstrap (module stubs, worlds stub, AutoStubFinder) as `generate_template.py`
- [ ] `docker.IntrospectOptions(ctx, apworldData, hash)` runs a one-shot container and returns JSON bytes
- [ ] `storage.UploadApworldOptionTypes` / `DownloadApworldOptionTypes` store/retrieve `{hash}.types.json`
- [ ] `service.UploadApworld` launches introspection in a goroutine after template generation; failure is Warn-only
- [ ] `GET /apworlds/{hash}/options` merges type overrides from `{hash}.types.json` into YAML-parsed options
- [ ] For `weights` type override, `defaultValue` is replaced with the `defaultWeights` map from introspection
- [ ] If `{hash}.types.json` does not exist (legacy apworlds), YAML-inferred types are returned unchanged
- [ ] `archipelago/Dockerfile` copies `introspect_options.py` to `/usr/local/bin/`

---

## Tasks

- [x] Write `archipelago/introspect_options.py`
- [x] Update `archipelago/Dockerfile` - add COPY for new script
- [x] Add `docker.IntrospectOptions()` to `orchestrateur/internal/docker/client.go`
- [x] Add `UploadApworldOptionTypes()` / `DownloadApworldOptionTypes()` to `orchestrateur/internal/storage/client.go`
- [x] Update `service.UploadApworld()` - launch introspection goroutine after meta upload
- [x] Add `service.GetApworldOptionTypes()` - download and parse `{hash}.types.json`
- [x] Update `handleGetApworldOptions()` - merge type overrides into parsed options

## Status: DONE

Validated live with 3 apworlds:
- Luigi's Mansion: `filler_weights`/`trap_weights` → weights (was choice) with real defaultWeights
- Starcraft 2: 5 OptionDict options reclassified (choice×37 → choice×35 + weights×5), including `filler_items_distribution`
- Blasphemous: `start_inventory_from_pool` → weights
- Legacy apworlds without `.types.json` continue to work (YAML-inferred types returned unchanged)
