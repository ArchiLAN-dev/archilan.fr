# Story 9.60: `start_inventory` verrouillé dans les éditeurs de YAML

**Status:** review
**Epic:** 9 - Sélection des jeux et YAML
**Date:** 2026-10-28
**Origine :** Jean le 2026-10-28 : « Le start inventory est cassé un peu sur les éditeurs yaml non ? »

## Story

En tant que joueur ou admin qui configure un jeu,
je veux pouvoir ajouter, renommer et retirer des items dans `start_inventory`,
afin de choisir les items de départ de mon slot quel que soit le jeu.

## Contexte

- Dans Archipelago, `StartInventory` et `StartInventoryPool` héritent de `ItemDict`, donc de `OptionDict` :
  `archipelago/introspect_options.py` les classe `type: "dict"`.
- `buildOption` (`frontend/src/lib/archipelago-yaml.ts`) traite le type déclaré avant les clés connues
  (story 9.33) : `declared === "dict"` rend un dict à clés figées (`fixedKeys: true`, fait pour `game_options`).
  La branche `FREEFORM_DICT_KEYS` (dict libre) n'est plus atteinte.
- Résultat : le YAML par défaut porte `start_inventory: {}`, l'éditeur affiche un dict vide sans bouton
  d'ajout ; une valeur existante a sa clé grisée et ne peut être retirée. Base locale le 2026-10-28 : 68 jeux
  sur 662 concernés (les apworlds réintrospectés), le nombre monte à chaque réintrospection.
- Les trois éditeurs (inscription événement, partie privée, hebdo admin) passent par ce parseur.

## Acceptance Criteria

1. `start_inventory` et `start_inventory_from_pool` restent des dicts libres (pas de `fixedKeys`), y compris
   quand l'introspection les déclare `dict` : ajout, renommage et retrait d'items possibles, autocomplétion des
   noms d'items (story 9.58) active.
2. Les autres options déclarées `dict` (ex. `game_options`) gardent leurs clés figées.
3. Test de non-régression avec `optionTypes.start_inventory.type = "dict"`, vide et rempli ; `pnpm gates` vert.

## Tasks / Subtasks

- [x] `buildOption` : clés libres connues avant le type déclaré (AC 1, 2)
- [x] Tests (AC 3)
- [x] Gates

## Dev Agent Record

### Completion Notes List

- Réalisation 2026-10-28 : la branche `FREEFORM_DICT_KEYS` passe avant `declared === "dict"` dans `buildOption`.
  Tests ajoutés dans `archipelago-yaml-dict-option.test.ts`.
