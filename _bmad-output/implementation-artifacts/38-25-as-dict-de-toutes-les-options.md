# Story 38.25 - `as_dict` de toutes les options, comme le Launcher

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-31
**Origine :** Caves of Qud (`cavesofqud` v0.8.2, hash `b07bcc592481`) a un incident « Erreur de génération » ouvert
depuis le 2026-09-27 (4 occurrences) : `AssertionError: Specify only options you need.` Remonté par Jean le
2026-10-31, reproduit ici.
**Dépôts :** `archipelago` (correctif, nouvelle image), `archilan.fr` (cette story).

## Constat

`CoQWorld.fill_slot_data` renvoie `self.options.as_dict(*toutes les options)`. `CommonOptions.as_dict`
d'Archipelago commence par deux `assert`, dont `len(option_names) < len(type_hints)`, « Specify only options you
need » : un conseil aux auteurs, pas une garde sur les données. Le reste de la méthode marche avec toutes les
options.

Le Launcher Windows tourne en mode optimisé (`__debug__` faux), ces `assert` n'y existent pas : Caves of Qud
génère chez les joueurs. Notre image tourne en Python normal et échoue, à la toute fin, dans `write_multidata`.
Même famille que la story 38.12 (accessibilité) : on ne passe pas l'image en `python -O`, qui retirerait aussi les
`assert` utiles (`Duplicate item reference`...), on assouplit ce seul contrôle.

## Décision

`generation_setup.allow_as_dict_of_every_option()` enveloppe `Options.CommonOptions.as_dict` : quand on lui
demande autant d'options (ou plus) que la classe en déclare, l'appel est découpé en lots plus petits et les
résultats fusionnés dans l'ordre demandé. Tous les autres cas passent tels quels, l'`assert` d'une liste vide et le
`ValueError` d'un nom inconnu compris. Branché dans `generate_multiworld.py` et `reachable.py`, comme
`soften_accessibility_check`.

## Critères d'acceptation

1. `as_dict` de toutes les options rend le même dictionnaire que le Launcher (ordre, casse, `toggles_as_bools`).
2. Un appel avec moins d'options ne change pas ; sans nom, l'`assert` d'origine reste ; un nom inconnu lève
   toujours `ValueError`.
3. Idempotent.
4. Docker, Caves of Qud aux options par défaut : génération réussie (avec l'avertissement d'accessibilité 38.12).
5. Tests unitaires ; `pytest` vert.

## Tâches

- [x] `generation_setup.py` : `allow_as_dict_of_every_option()`.
- [x] Branché dans `generate_multiworld.py` et `reachable.py`.
- [x] Tests `tests/test_as_dict_every_option.py`.
- [x] Vérification Docker.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes

- `allow_as_dict_of_every_option()` enveloppe `Options.CommonOptions.as_dict` : au-delà de `len(type_hints) - 1`
  noms, l'appel est découpé en lots de cette taille, chacun passant par la méthode d'origine (casse,
  `toggles_as_bools`, `ValueError` d'un nom inconnu inchangés), et les résultats sont fusionnés dans l'ordre.
- Branche empilée sur celle de la 38.24 (mêmes imports dans `generate_multiworld.py`).
- Tests : 3 nouveaux ; `pytest` 230 passed.
- Docker, `cavesofqud` v0.8.2 (sha256 `b07bcc59...`, celui de l'incident), options par défaut :
  - `archipelago:latest` : `AssertionError: Specify only options you need.` reproduite ;
  - image de la branche : génération réussie en 10 s, slot data avec les 11 options communes et les 6 du jeu
    (`goal`, `genotype`, `locations_per_level`, `extra_location_levels`, `trap_percentage`, `lost_artifacts`).
- Reste l'avertissement d'accessibilité (lieux `Level 20.0` et au-delà inatteignables aux options par défaut) :
  comportement de la story 38.12, le verdict passe avec un encadré. C'est l'apworld, rien à corriger chez nous.

### Mise en prod

1. Merger les PR `archipelago` 38.24 puis 38.25, publier l'image.
2. Fiche admin de Caves of Qud : relancer le test de l'apworld, puis résoudre l'incident.

### File List (dépôt archipelago)

- `generation_setup.py`
- `generate_multiworld.py`
- `reachable.py`
- `tests/test_as_dict_every_option.py` (nouveau)
