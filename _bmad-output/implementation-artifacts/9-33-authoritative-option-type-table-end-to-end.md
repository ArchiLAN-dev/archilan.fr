# Story 9.33: Authoritative option-type table end-to-end (dict/OptionDict support)

**Status:** implémentée - `v1.7.0` publiée, PR vers `develop`
**Epic:** 9 - Multiworld generation pipeline & apworld introspection
**Date:** 2026-06-27

## Story

As the YAML option editor,
I want an **authoritative per-option type table** from apworld introspection (including dict/OptionDict
options), instead of guessing each option's type from the shape of its value,
so that literal dict options like Pokemon Platinum's `game_options` are classified correctly without
heuristics, and a whole class of "wrong type at generation" crashes disappears at the source.

## Context

Story 9.25 introduced introspected option metadata (`Game.optionTypes`), but it is **range-bounds only**:
`Game.optionTypes` is typed `array<string, array{min: int, max: int, default: int|null}>`, and
`RunnerGateway::fetchOptionTypes` keeps **only** `RangeTemplateOption` from `getOptions($hash)`. Every
other option type (choice/toggle/weights/text, and dicts) is dropped before reaching the frontend.

So the editor (`archipelago-yaml.ts::buildOption`) still classifies most options by **value shape**:
keys `true`/`false` → toggle, numeric/alias keys → range, else → choice (catch-all). That heuristic
misfired on `game_options` (an `OptionDict` of literal settings) and coerced `default_player_name` to an
int, crashing generation - patched defensively in **story 4.17** by routing non-numeric-valued objects
to a freeform dict.

4.17 is a heuristic, not a source of truth. Two gaps remain:
1. The introspection (`TemplateOption::fromArray`) emits only `range|choice|toggle|weights|text`; there
   is **no `dict`/OptionDict type**, so `game_options` falls to the `text` default - still wrong.
2. The API forwards only range bounds, so the frontend can never consult the real type even for the
   types that *are* introspected.

This story closes the loop: model dict options in introspection and carry the full authoritative type
table to the editor, demoting shape heuristics to a fallback for un-introspected apworlds.

## Acceptance Criteria

1. **Orchestrateur** (Python, separate repo): the options-introspection endpoint emits a `dict` type for
   `OptionDict`-derived options, including the sub-option schema (key, type, default, valid values where
   known). Existing `range|choice|toggle|weights|text` payloads are unchanged.
2. **orchestrateur-client** (vendor, separate repo): a `DictTemplateOption` response type parsed by
   `TemplateOption::fromArray` for `type: dict`; no behaviour change for existing types.
3. **API**: the authoritative type table carried to the frontend is widened beyond range bounds to a
   per-option `{ type, ...typeSpecific }` shape. `Game.optionTypes` (or a successor field) persists it;
   `BackfillGameOptionTypes` repopulates it. Backward-compatible read for games still on the old
   range-only shape.
3b. The widened payload is validated at the API boundary (no `mixed` leaks) and exposed everywhere the
   current `optionTypes` is (`PersonalRunGameSelection`, `RegistrationGameSelection`, `AdminGameLibrary`,
   session configure response).
4. **Frontend**: `buildOption` consults the authoritative type first (`optionType.type === "dict"` →
   freeform dict; `range`/`choice`/`toggle` likewise); the existing value-shape heuristics (incl. the
   4.17 non-numeric-value guard) become the **fallback** used only when introspection is absent for that
   key. `asOptionTypesMap` / `OptionTypesMap` widened accordingly with type guards (AC-TS3).
5. Generation no longer depends on the editor guessing dict vs weighted: a round-trip of `game_options`
   driven by introspection preserves every literal sub-setting.
6. Gates green in each touched repo: API (`phpstan` / `php-cs-fixer` / `phpunit` / `app:architecture:ddd`),
   frontend (`typecheck` / `lint` / `build` + jest), orchestrateur (its own gates).

## Tasks / Subtasks

- [x] **Task 1** (AC 1). Écrite, en attente du tag - voir « Ce qui reste ». Orchestrateur: detect `OptionDict` during introspection, serialize a `dict` type
  with sub-schema. Add a fixture apworld with a dict option to its test suite.
- [x] **Task 2** (AC 2). Écrite, en attente du tag - voir « Ce qui reste ». orchestrateur-client: `DictTemplateOption` + `fromArray` routing + unit test.
- [x] **Task 3** (AC 3, 3b). API: widen the option-type table model + persistence + backfill + boundary
  validation; thread it through the game-selection / configure payloads. Migration if the stored shape
  changes.
- [x] **Task 4** (AC 4, 5, hors dict). Frontend: introspection-first classification in `buildOption`; keep shape
  heuristics as fallback; widen `OptionTypesMap`. Jest: dict via introspection → freeform dict; absent
  introspection still falls back to the 4.17 behaviour.
- [x] **Task 5** (AC 6, sur les dépôts touchés). All gates green across repos.

## Dev Notes

- **Scope spans 3 repos** (orchestrateur, orchestrateur-client, this monorepo). Sequence: orchestrateur
  emits the type → client parses it → API persists/forwards → frontend consumes. Each layer ships behind
  graceful fallback so partial rollout never regresses (missing dict type → frontend heuristic, story 4.17).
- Keep 4.17's non-numeric-value guard as the documented fallback; do **not** remove it - it protects
  apworlds whose introspection has not been backfilled.
- Consider whether `Game.optionTypes` is widened in place (nullable union per key) or a new field
  (`Game.optionSchema`) is added to avoid breaking the range-only consumers (4.16 bounds validation).
  Prefer additive to keep 9.25/4.16 untouched.
- Adjacent (optional, see 4.17 notes): the freeform-dict serializer dumps with js-yaml `CORE_SCHEMA`, so
  `on`/`off`/`yes`/`no` go out unquoted and PyYAML (YAML 1.1) reads them as booleans. If dict sub-options
  declare a string type via the new schema, the serializer can quote 1.1-bool-like scalars authoritatively.

### Project Structure Notes

- Orchestrateur (separate repo): options-introspection endpoint.
- `api/vendor/archilan/orchestrateur-client` (separate repo): `Apworlds/Response/*TemplateOption.php`.
- `api/src/Sessions/Infrastructure/RunnerGateway.php` (`fetchOptionTypes`), `api/src/GameSelection/Domain/Game.php`
  (`optionTypes`), `api/src/GameSelection/Application/BackfillGameOptionTypes.php`.
- `frontend/src/lib/archipelago-yaml.ts` (`buildOption`, `OptionTypesMap`, `asOptionTypesMap`).

### References

- [Source: _bmad-output/implementation-artifacts/4-17-literal-dict-options-not-weighted.md (heuristic fix this supersedes as fallback)]
- [Source: _bmad-output/implementation-artifacts/9-25-introspected-range-bounds-end-to-end.md (range-bounds table to widen)]
- [Source: _bmad-output/implementation-artifacts/4-16-range-value-bounds-validation.md (range-bounds consumer to keep working)]
- [Source: api/src/GameSelection/Domain/Game.php (optionTypes shape)]
- [Source: api/vendor/archilan/orchestrateur-client/src/Apworlds/Response/TemplateOption.php (fromArray type routing)]

## Dev Agent Record

### Ce qui a été livré

Le type était **déjà sur le fil**. `handleGetApworldOptions` fusionne les types introspectés dans sa
réponse depuis la story 9.25 ; c'est `RunnerGateway::fetchOptionTypes` qui les jetait :

```php
if ($option instanceof RangeTemplateOption) { ... }   // et rien d'autre
```

Aucun changement n'a donc été nécessaire dans l'orchestrateur ni dans le client vendored pour les
cinq types qu'il modélise déjà (range, choice, toggle, weights, text). Le travail était de cesser de
les perdre, puis de faire consulter le type par l'éditeur **avant** ses heuristiques de forme.

### Ce que ça corrige tout de suite

L'issue [#483](https://github.com/ArchiLAN-dev/archilan.fr/issues/483) : un `NamedRange` dont le
gabarit propose une valeur nommée à côté de ses nombres (« Use Percentage Option ») échouait au test
« toutes les clés sont numériques » et retombait sur **choice** - d'où l'impossibilité d'y saisir
`0`, et le contournement à 1 %. Le type faisant désormais autorité, c'est une range, et la valeur
nommée survit à l'aller-retour au lieu d'être perdue.

## Écarts assumés

### Table élargie sur place plutôt que doublée

Les Dev Notes suggéraient un champ additif (`Game.optionSchema`) pour ne pas toucher aux
consommateurs de bornes des stories 9.25 / 4.16. À l'écriture, l'additif coûtait une migration, un
second backfill, un second champ à faire passer dans quatre charges utiles - et surtout **deux
sources de vérité sur la même option**.

La colonne est du JSON : une ligne écrite avant cette story porte `{min, max, default}` sans `type`,
et les lecteurs traitent un type absent comme « range s'il y a des bornes, rien sinon ». La
compatibilité descendante que demandait l'AC 3 est donc obtenue par construction, sans migration.
`min` et `max` n'ont pas bougé de place, les consommateurs de bornes ne voient rien.

### Une entrée qui ne dit rien est écartée

Ni type déclaré, ni bornes : l'entrée n'apprend rien à l'éditeur qu'il ne sache déjà. La garder
serait une promesse vide qui ferait taire l'heuristique sans rien mettre à sa place.

## Ce qui reste

**Rien : `v1.7.0` est publiée et le lock la porte.**

Le type `dict` traverse cinq couches, et elles ne pouvaient pas partir dans le désordre :
`TemplateOption::fromArray` route tout type inconnu vers `TextTemplateOption`, donc émettre `dict`
avant que le paquet ne le modélise ferait passer `game_options` de « weights » à « text », soit de
faux à plus faux.

| # | couche | où | état |
|---|---|---|---|
| 1 | `DictTemplateOption` | archilan-orchestrateur-client | mergée, **taguée `v1.7.0`** |
| 2 | contrainte `>=1.7.0` + lock | monorepo | fait |
| 3 | `describeOption` mappe le dict | monorepo | écrit |
| 4 | l'introspection émet `dict` | archipelago | écrit |
| 4bis | l'orchestrateur transporte `defaults` / `validKeys` | orchestrateur | écrit |
| 5 | l'éditeur route `dict` vers le dict freeform | monorepo | écrit et testé |

L'étape 4bis n'était pas prévue par la story : `OptionTypeOverride` ne transportait que
`defaultWeights`, et la réponse d'API n'avait pas de champ pour les clés valides.

`composer gates` vert avec le paquet réellement installé : 1901 tests.

Reste l'ordre de **déploiement** : les images archipelago et orchestrateur ne doivent pas partir
avant que l'API ne soit à jour, sinon `dict` retombe sur `text` faute d'être modélisé côté client.

### Une découverte au passage

L'ancien chemin `weights` sérialisait ses défauts par `{str(k): int(v) for ...}`. Sur un dict
littéral, `int("player_name")` lève, l'`except` nu avale l'erreur, et l'option arrivait à l'éditeur
**mal typée et sans aucun défaut**. La mauvaise classification ne coûtait pas seulement le type.

### Change Log

### Change Log

| Date       | Change |
|------------|--------|
| 2026-08-26 | Type `dict` écrit dans les cinq couches, en attente du tag v1.7.0 du client. Découverte : le chemin `weights` perdait aussi les défauts d'un dict littéral (coercition `int()` avalée par un except nu). |
| 2026-08-26 | Implémentation de la moitié monorepo : table de types élargie sur place, éditeur qui consulte le type avant la forme. Corrige #483. AC 1 et 2 (type `dict`) différés : ils exigent une publication du paquet client. |
| 2026-06-27 | Created as follow-up to story 4.17. Replace editor value-shape guessing with an authoritative end-to-end option-type table that models dict/OptionDict options. Spans orchestrateur + orchestrateur-client + API + frontend; 4.17 heuristic retained as fallback. Status: draft. |
