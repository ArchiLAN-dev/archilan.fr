# Story 9.47: Manual platform override on a game

Status: review
Related: 28.6 (IGDB platform resolution), 9.45 (editable default template - same "IGDB describes
the game, we describe the Archipelago world" tension)

## Story

As an **admin curating the game library**,
I want **to choose which platforms are shown for a game**,
so that **the catalog advertises the platforms the Archipelago world actually supports, not
every platform the game was ever released on**.

## Context

Platforms come from IGDB: `GamePlatformResolver` stores the raw list on the game's catalog
sync, and `PlatformCategory::families()` collapses ~150 noisy IGDB entries into curated
families (PC, Switch, PlayStation…). That is right for describing **the game**, and wrong for
describing **the Archipelago world**: a game released on 8 platforms often has an apworld that
only works on one (a PC-only client, a specific emulator, a ROM-patching flow).

Today an admin has no say: the only action is "Synchroniser depuis IGDB", which overwrites
the list with IGDB's answer. The value is read in six places - admin detail, admin event game
selection, personal-run game selection (x2) and the public catalog (two DBAL queries) - so
the fix must not be a display hack in one of them.

## Acceptance Criteria

**AC1 - Override, don't overwrite:** a game can carry an explicit list of platform families
chosen by an admin. It is stored separately from the IGDB data, so a later IGDB resync
updates the raw platforms without discarding the admin's choice.

**AC2 - One rule, one place:** the effective list is `override ?? families(igdb)`, resolved
by a single shared function used by the entity **and** the catalog DBAL queries. No call site
re-implements the precedence.

**AC3 - Closed vocabulary:** the admin picks from the curated families
(`PlatformCategory`), so the catalog filters keep working. A save is rejected when it
contains an unknown family, and an empty selection is rejected - a game with no platform at
all would vanish from the platform filters.

**AC4 - Reversible:** the admin can drop the override and return to the IGDB-derived list in
one action. The UI states which of the two is currently in effect.

**AC5 - Visible everywhere:** the effective list is what the public catalog, the game
pickers and the admin page all show - the override is not admin-only decoration.

**AC6 - Quality gates:** api `composer gates`, frontend `pnpm gates`.

## Tasks / Subtasks

- [x] Task 1: migration - nullable JSON column on `game` for the override.
- [x] Task 2: domain - `PlatformCategory::resolve()` + the curated family list exposed for
      the UI; `Game::overridePlatformFamilies()` / `Game::platformFamilies()` (AC1, AC2).
- [x] Task 3: replace the six read sites with the resolved value, including the two DBAL
      catalog queries (AC2, AC5).
- [x] Task 4: api - save command with validation (AC3), clear action (AC4), admin endpoint,
      detail payload exposing the effective list, whether it is overridden, and the
      selectable families; unit tests.
- [x] Task 5: frontend - platform picker in the admin game page with "Revenir aux plateformes
      IGDB" and a clear statement of which source is in effect (AC3, AC4).
- [x] Task 6: gates (AC6).

## Dev Notes

- The override lives on `game`, not on `game_catalog_sync`: it is an editorial decision, not
  synced data, and keeping it out of the sync table is what makes AC1 hold by construction.
- Unmapped IGDB platforms currently fall back to their raw name, so the *derived* list can
  contain values outside the curated set. The override is restricted to the curated set
  (AC3); an admin who needs an exotic value keeps the derived list.
- Not in scope: per-platform notes ("Switch via emulator only"), and touching how
  `GamePlatformResolver` fetches IGDB data.
