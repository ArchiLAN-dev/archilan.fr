# Story 54.4 - Le moteur de pack

**Status:** review
**Epic:** 54 - Trackers de jeu, les packs PopTracker sur le site (`_bmad-output/planning-artifacts/epics/epic-54-trackers-poptracker.md`)
**Date:** 2026-11-16
**Branche:** `feature/epic-54-story-4-moteur-de-pack`
**Dépend de:** rien côté API (un zip local suffit pour développer). C'est la story la plus risquée de l'epic.

## Story

En tant que joueur,
je veux que le site comprenne un pack PopTracker comme PopTracker le fait,
afin que le tracker me dise juste ce qui est accessible.

## Contexte

- Spec de référence : `doc/PACKS.md` et `doc/AUTOTRACKING.md` du dépôt `black-sliver/PopTracker` (GPLv3). **Lire la
  spec, ne copier ni le code C++ ni `api/lua/definition/poptracker.lua`.**
- Ce que le pack MM utilise réellement : voir l'epic (inventaire du 2026-11-16).
- Les items, cartes et locations sont chargés **par le Lua** (`init.lua` appelle `Tracker:AddItems`, `AddMaps`,
  `AddLocations`, `AddLayouts`). Le Lua ne sert donc pas qu'aux règles.
- Ce moteur n'a pas d'interface : 54.5 l'affiche, 54.6 le branche sur Archipelago.

## Décisions

1. **Emplacement** : `frontend/src/features/tracker/engine/`. TypeScript pur, testable sous Jest, sans React.
2. **Dépendances** (versions exactes, licences vérifiées) : `wasmoon` (Lua 5.4 en WASM), `fflate` (zip),
   `jsonc-parser` (JSONC tolérant : commentaires, virgules finales ; BOM retiré avant).
3. **Web Worker** : tout le moteur tourne dans un Worker (`tracker.worker.ts`). Le fil principal ne voit qu'un
   protocole de messages typés :
   - entrées : `load {archive: ArrayBuffer, rootPath, variantUid}`, `itemClick {code, button: left|right}`,
     `itemSet {code, state}`, `sectionClear {path, count?}`, `sectionUnclear {path}`, `bulk {actions[]}` ;
   - sorties : `loaded {manifest, items, maps, layouts, locations}`, `state {items, sections}` (diff après chaque
     changement), `error {kind, message, file?, line?}`, `log {level, message}`.
4. **Système de fichiers du pack** : archive décompressée en mémoire, racine `rootPath`. Recherche des fichiers comme
   PopTracker : d'abord `<variantUid>/chemin`, puis `chemin` (fichiers propres à une variante). Images exposées en
   `Blob` ou `ImageBitmap` pour l'interface.
5. **Bac à sable Lua** :
   - retirer `io`, `debug`, `os` (sauf `os.time`, `os.clock`, `os.date`), `package.loadlib`, `dofile`, `loadfile`,
     `collectgarbage` hors `count`, et le chargement de bytecode (`load` limité au mode `"t"`) ;
   - **aucune interop JS** exposée au Lua hors du shim (désactiver la bibliothèque `js` de wasmoon et vérifier qu'aucun
     objet global JS n'est atteignable) ;
   - `require` et `ScriptHost:LoadScript` résolus dans le pack seulement (règles `require` de la spec : `scripts/x/y.lua`,
     `scripts/x/y/init.lua`, `x/y.lua`, `x/y/init.lua`) ;
   - garde-fou de temps : le fil principal tue le Worker si un message n'a pas de réponse au bout de 10 s, et affiche
     une erreur « le pack ne répond plus ».
6. **Shim des globales** (périmètre minimal = ce qu'utilise MM, plus le reste de la spec quand c'est trivial) :
   - `Tracker` : `ActiveVariantUID`, `AddItems`, `AddMaps`, `AddLocations`, `AddLayouts`, `AddClasses` (lu et ignoré,
     log), `ProviderCountForCode`, `FindObjectForCode` (item par code, section par `@zone/location/section`),
     `UiHint` (ignoré, log), `BulkUpdate`, `AllowDeferredLogicUpdate` ;
   - `ScriptHost` : `LoadScript`, `AddWatchForCode`, `RemoveWatchForCode`, `AddMemoryWatch` et autres fonctions
     mémoire → pas de plantage, renvoient nil et loguent « autotracking mémoire non pris en charge » ;
   - `AutoTracker:GetConnectionState(name)` ; `AccessibilityLevel` (`None`, `Partial`, `Inspect`, `SequenceBreak`,
     `Normal`, `Cleared`) ; `Highlight` (`Avoid`, `None`, `NoPriority`, `Unspecified`, `Priority`) ;
   - `PopVersion` = `"0.34.0"` (constante `EMULATED_POPTRACKER_VERSION`, comparée en chaîne par les packs) ;
   - `Archipelago` : objet présent, handlers enregistrés, `PlayerNumber = -1`. Le réseau arrive en 54.6.
   - Toute fonction absente appelée par un pack → erreur `unsupported_api` avec son nom (sert le rapport de 54.8).
7. **Items** : types `toggle`, `progressive` (`stages`, `allow_disabled`, `loop`, `initial_stage_idx`), `consumable`
   (`min_quantity`, `max_quantity`, `increment`, `decrement`, `initial_quantity`), `progressive_toggle`,
   `composite_toggle`, `toggle_badged`, `static`. Propriétés Lua : `Active`, `CurrentStage`, `AcquiredCount`,
   `MinCount`, `MaxCount`, `Name`, `Type`. Codes multiples (`"codes": "a,b,c"`). `ProviderCountForCode` suit la spec
   (somme des quantités pour les consumables). `Tracker:CreateLuaItem` : hors périmètre, erreur `unsupported_api`.
8. **Locations** : arbre `children`, `parent` par nom, `sections` (`item_count`, `hosted_item`, `clear_as_group`,
   `ref`), `map_locations`, `visibility_rules` et `access_rules` héritées du parent (ET). Propriétés Lua des sections :
   `ChestCount`, `AvailableChestCount`, `AccessibilityLevel`, `Highlight`.
9. **Évaluateur de règles** (`rules.ts`, sans dépendance au Lua pour la partie grammaire) : ET par virgule, OU entre
   entrées, `$f|a|b`, `^$f`, `@zone/section`, `code:n`, `[...]` (séquence break), `{...}` (inspect). Retour booléen ou
   nombre. Accessibilité d'une location : `Partial` si certaines de ses sections seulement sont accessibles, `Cleared`
   si tout est fait.
10. **Cache** : résultats des règles mis en cache, invalidés à chaque changement d'item (ou en fin de `BulkUpdate`). Les
    watchers `AddWatchForCode` sont appelés après le changement, hors `BulkUpdate`. Détection de boucle `@` → erreur,
    pas de récursion infinie.
11. **Fixtures** : un mini-pack écrit par nous dans `frontend/src/features/tracker/engine/__fixtures__/` (3 items,
    2 locations, une règle de chaque forme, une variante, du Lua avec `require`). Le pack MM (MIT) n'est **pas**
    commité : un test d'intégration le charge s'il est présent dans un dossier local (variable d'environnement),
    sinon il est ignoré.

## Critères d'acceptation

1. Les 3 variantes du pack MM v0.9.5.3 se chargent sans erreur Lua ni `unsupported_api`.
2. Sans aucun item, « Woodfall Temple Entrance Chest SF » n'est pas accessible. Avec les items de la règle
   `woodfall_temple()` en logique normale (Deku, Ocarina, Sonata, et une bouteille pour `south_swamp`), il l'est.
   « Woodfall Temple Switch Chest SF » demande en plus l'arc.
3. Pour 4 états d'inventaire notés dans la story, l'accessibilité calculée de 10 sections MM est la même que dans
   PopTracker 0.34 de bureau (relevé fait à la main par le dev, gardé dans le fichier de test).
4. Un script qui boucle à l'infini ne fige pas la page : le Worker est arrêté et l'erreur s'affiche.
5. Depuis le Lua d'un pack, `io`, `os.execute`, `debug` et le JS du navigateur sont inaccessibles.
6. Charger le pack MM et calculer l'état initial prend moins de 3 s sur un portable moyen. Un changement d'item met à
   jour l'état en moins de 100 ms.

## Tests et gates

7. Unitaires : grammaire des règles (chaque forme, combinaisons, boucle `@`), types d'items et clics, héritage des
   règles, recherche de fichiers par variante, résolution de `require`, bac à sable (accès interdits).
8. Intégration : mini-pack de bout en bout dans le Worker (ou sa logique hors Worker sous Jest) ; MM si présent.
9. `pnpm gates` vert (vérifier que le WASM de wasmoon passe le build Next et le CSP du site).

## Tasks / Subtasks

- [x] **Task 1** (AC 5, 9) - Dépendances, Worker, protocole de messages, CSP et build.
- [x] **Task 2** (AC 1) - Système de fichiers du pack, JSONC, manifest, variantes.
- [x] **Task 3** (AC 4, 5) - Bac à sable Lua et garde-fou de temps.
- [x] **Task 4** (AC 1, 2) - Shim `Tracker`, `ScriptHost`, enums, items, locations.
- [x] **Task 5** (AC 2, 3, 6) - Évaluateur de règles, cache, watchers.
- [x] **Task 6** (AC 3, 7, 8) - Fixtures, tests, relevé comparatif avec PopTracker. *(grille posée et vérifiée contre les règles du pack ; colonne PopTracker de bureau à relever à la main, voir plus bas)*

## Dev Agent Record

### Fichiers

`frontend/src/features/tracker/engine/` :

- `pack-files.ts` (zip en mémoire via fflate, racine `rootPath`, dossier de la variante d'abord, chemins qui sortent du pack refusés), `jsonc.ts` (BOM, commentaires, virgules finales, ligne de l'erreur), `errors.ts`, `json-read.ts` ;
- `items.ts` (les 7 types, clics, codes hérités par étape, bornes des consumables), `locations.ts` (arbre, `parent`, sections, `ref`, cartes ; ids uniques), `rules.ts` (grammaire sans Lua), `levels.ts` (`AccessibilityLevel`, `Highlight`, `EMULATED_POPTRACKER_VERSION`) ;
- `lua-bootstrap.ts` (bac à sable + shim `Tracker`, `ScriptHost`, `AutoTracker`, `Archipelago`, `ImageReference`, enums, `require`) et `tracker-engine.ts` (pont JS-Lua, cache, watchers, diff d'état) ;
- `protocol.ts` (messages typés et gardes), `worker-host.ts` (logique du Worker, testable sous Jest), `tracker.worker.ts`, `create-tracker-worker.ts`, `tracker-client.ts` (côté page, garde-fou de 10 s), `empty-node-module.ts` ;
- tests : `rules`, `items`, `pack-files`, `tracker-engine` (mini-pack, bac à sable, erreurs), `worker-host` (zip de bout en bout), `tracker-client` (délai, crash), `mm-pack` (si `TRACKER_MM_PACK_DIR`), `wasm-asset` ;
- `__fixtures__/mini-pack/` : manifest avec BOM et JSONC, 2 variantes (`compact/` surcharge un layout), `require("logic.helpers")`, chaque forme de règle, une boucle `@`, un `ref`, un `hosted_item`, une `visibility_rules`, un `parent`, un watcher, des handlers Archipelago.

Hors du dossier : `public/tracker/wasmoon-1.16.0.wasm` (copie du `glue.wasm`), `next.config.ts` (CSP, alias Turbopack), `jest.config.mjs` (wasmoon transformé), `package.json` (`wasmoon` 1.16.0, `fflate` 0.8.2, `jsonc-parser` 3.3.1 : versions exactes, licence MIT pour les trois).

### Décisions prises en cours de route

- **Protocole par id, pas par code ni par chemin.** `itemClick`/`itemSet` prennent `itemId`, `sectionClear`/`sectionUnclear` prennent `sectionId`, et l'état est indexé par id. Raisons : un item peut n'avoir aucun code (décor), et MM a plusieurs locations au même chemin (`Beneath the Well/` : enfants sans nom). Les chemins restent pour les règles `@` et l'affichage ; `loaded` donne les ids.
- **Worker classique, pas module** : la glue Emscripten de wasmoon ne reconnaît un Worker que par `importScripts`.
- **WASM servi par le site** (`/tracker/wasmoon-<version>.wasm`) : sans URI, wasmoon irait le chercher sur unpkg. `wasm-asset.test.ts` vérifie que la copie suit la version installée.
- **Turbopack** : `resolveAlias` de `module` vers un module vide côté navigateur (wasmoon l'importe sur sa branche Node, jamais prise dans un Worker). Sans ça le build échoue (`Can't resolve 'module'`).
- **Jest** : wasmoon ajouté aux paquets transformés (son `await import("module")` est refusé par le runtime CJS de Jest).
- **CSP** (toujours en Report-Only) : `'wasm-unsafe-eval'` dans `script-src` (WASM seulement, pas d'eval JS) et `worker-src 'self' blob:`. Aucune violation remontée dans la console pendant la vérif.
- **Bac à sable** : le pont JS est une locale du bootstrap (le global est supprimé) et ne fait passer que chaînes, nombres et booléens ; les objets vus par le Lua (items, sections) sont des tables à métatable qui appellent le pont. `load` est forcé en mode `"t"`, `string.dump` retiré.
- **`@Location` sans section** : niveau des `access_rules` de la location et de ses parents. Le niveau affiché d'une location (`state.locations`) est calculé sur ses sections visibles (`Partial` si une partie seulement est accessible, `Cleared` si tout est fait).
- **Interprétations de la spec à confirmer dans PopTracker** (peu utilisées) : `progressive_toggle` clic gauche = on/off, clic droit = étape suivante ; `clear_as_group` vaut `true` par défaut ; dé-cocher une section non groupée rend un check à la fois.
- **Watchers** : rejoués après chaque action (et après le chargement), retenus tant que `Tracker.BulkUpdate` vaut `true` ; plus de 100 tours de suite = arrêt et log. Une règle `$` qui plante vaut faux et n'est loguée qu'une fois.
- **Fonctions absentes** : `Tracker`, `ScriptHost` et `Archipelago` renvoient, pour toute clé inconnue, une fonction qui lève `unsupported_api: Owner:Nom` et l'enregistre dans `unsupportedCalls` (pour 54.8). Les fonctions mémoire (`AddMemoryWatch`, `AutoTracker:ReadU8`...) renvoient `nil` et loguent une fois.

### Vérifications

- **AC 1** : les 3 variantes de MM v0.9.5.3 se chargent, sans `unsupported_api` ni erreur Lua. Deux logs d'erreur restent et viennent du pack : `init.lua` charge `scripts/inverse_rules.lua` alors que le fichier s'appelle `inverse_logic.lua`, d'où aussi `$small_key_sanity_off` absente. PopTracker affiche la même chose.
- **AC 2** : Woodfall Temple Entrance Chest SF à None sans item, Normal avec Deku, Ocarina, Sonata et une bouteille ; Switch Chest SF demande en plus l'arc (test et navigateur).
- **AC 3** : grille de 10 sections x 4 inventaires dans `mm-pack.test.ts`. Les 40 valeurs attendues ont été écrites en lisant les règles et le Lua du pack avant de lancer le moteur, et le moteur les retrouve toutes. **Reste à faire par Jean** : relever les mêmes 40 cases dans PopTracker 0.34 de bureau et corriger la grille en cas d'écart.
- **AC 4** : dans Chrome, avec un `init.lua` en `while true do end`, la page reste fluide (le Worker tourne à part) et le client tue le Worker à 10,0 s avec « Le pack ne répond plus. ». Couvert en Jest par `tracker-client.test.ts` (faux timers).
- **AC 5** : depuis un pack chargé dans le navigateur, `io`, `os.execute`, `debug`, `js`, `window`, `fetch` valent `nil`. En Jest : aucun userdata atteignable depuis `_G` ni depuis la métatable des chaînes, bytecode refusé, `require`/`LoadScript` hors du pack refusés.
- **AC 6** : MM dans Chrome via le Worker (build de prod) : 164 ms pour charger et calculer l'état initial, 5 ms pour un changement d'item. Sous Node : environ 1,4 s pour le premier chargement (démarrage du WASM compris).
- **AC 9** : `pnpm gates` vert (typecheck, lint, tests, build). Le build s'arrête une fois sur l'avertissement de sécurité de Next 16.4.3 (corrigé par le hotfix v0.66.3 en attente) et passe au second essai.

### Pour lancer les tests MM

```
TRACKER_MM_PACK_DIR=/chemin/vers/Majoras-Mask-AP-PopTracker-Pack-0.9.5.3 pnpm jest mm-pack
```
