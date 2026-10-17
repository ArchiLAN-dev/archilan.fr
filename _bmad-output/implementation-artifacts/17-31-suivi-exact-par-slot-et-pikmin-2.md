# Story 17.31: Suivi « faisable » exact slot par slot, et Pikmin 2 rejoué en secours

**Status:** review
**Epic:** 17 - Sessions et suivi des slots
**Date:** 2026-10-17

## Story

En tant que joueur d'une partie à plusieurs,
je veux que le suivi « ce qui est faisable » de mon slot repose sur les vraies règles de ma partie,
afin de ne pas voir comme faisables des emplacements qui ne le sont pas (et inversement).

## Contexte

Signalé par Jean le 2026-10-17 sur une partie de 9 slots (Pikmin 2, onions dans le pool). Le suivi affichait
faisables les 5 emplacements de la Valley of Repose qui demandent des Pikmin bleus, alors que le Blue Onion
n'était pas reçu.

Diagnostic sur banc local, avec l'archive, les YAML et les 8 apworlds de la partie (mode exact puis secours de
`archipelago/reachable.py`, story 17.28 et mémoire « Suivi exact (reachable) ») :

1. **La régénération exacte échoue à cause d'un autre slot.** Pokémon Battle Revolution tire ses Colisées de
   départ dans un `set` converti en liste (`pokemon_revo/items.py`) : l'ordre d'un `set` de chaînes change à
   chaque process Python, donc l'inventaire de départ du slot 2 ne se reproduit jamais (trois régénérations,
   trois paires de Colisées). Le garde-fou `_exact_mismatch` étant global, **tous** les slots passaient en secours,
   alors que la régénération exacte du slot Pikmin 2 était juste et stable.
2. **Le secours retire au hasard l'onion de départ de Pikmin 2.** Le monde est régénéré seul avec une seed neuve ;
   Pikmin 2 tire dans `create_regions` l'onion de départ, les grottes et l'ordre des sous-niveaux, et n'a pas de
   support Universal Tracker. Sur 6 calculs : 4 avec 60 emplacements faisables (départ bleu, faux), 2 avec 23
   (juste).

## Critères d'acceptation

1. Le garde-fou de la régénération exacte juge **slot par slot** (emplacements et inventaire de départ) : un slot
   qui ne se reproduit pas passe seul en secours, les autres gardent le monde exact. Le monde exact n'est
   abandonné que si aucun slot ne se reproduit.
2. En secours, Pikmin 2 reprend `onion_locations`, `caves` et `sublevels` de ses `slot_data` au lieu de les
   retirer.
3. Sur la partie signalée : slot 8 en mode exact, 23 emplacements faisables avec les items reçus, aucun des 5
   emplacements à bleus ; en secours forcé (spoiler retiré), le même résultat à chaque calcul.
4. Tests unitaires : garde-fou par slot, démon de session (seul le slot divergent est reconstruit seul),
   rejeu Pikmin 2.

## Tâches

- [x] **Task 1** (AC1) - `_exact_mismatches` (slot -> raison) remplace `_exact_mismatch` ;
  `_load_exact_multiworld` rend le monde et les slots divergents ; `_SessionTrackers` ne bascule que ceux-là.
- [x] **Task 2** (AC2) - `_pikmin2_replay` dans `_SLOT_DATA_REPLAY` (stade `before_generate_early` : les trois
  tirages du monde répondent avec les valeurs de la seed).
- [x] **Task 3** (AC3) - Banc local sur la partie signalée.
- [x] **Task 4** (AC4) - Tests (`test_exact_regeneration.py`, `test_session_daemon.py`, `test_slot_data_replay.py`).

## Dev Notes

- Pourquoi un slot peut rester exact quand un autre diverge : chaque monde tire de son propre `random`, semé une
  fois pour tous les mondes avant toute génération ; PBR diverge par l'ordre d'un `set`, sans toucher au
  `random` partagé. Un monde qui tirerait du `random` partagé pendant la génération pourrait décaler les
  autres ; le contrôle par slot (emplacements, inventaire de départ) reste en place pour chacun.
- Bugs amont signalés (textes d'issue préparés pour Jean) : support Universal Tracker absent de Pikmin 2
  (chpas/Pikmin2Archipelago), tirage des Colisées dépendant de l'ordre d'un `set` dans PBR.

## Dev Agent Record

### Completion Notes

- Banc (image `archipelago:latest`, réseau coupé, `reachable.py` du dépôt, observateur « Bridge » recréé comme
  l'orchestrateur d'avant 9.56) : avant, « exact regeneration diverges (slot 2: starting inventory differs),
  falling back » puis 4 calculs faux sur 6 ; après, slot 2 seul en secours, slot 8 exact, 23 emplacements 3 fois
  sur 3 ; spoiler retiré, 23 emplacements 6 fois sur 6.
- `pytest` : 181 tests verts.

### File List

- `archipelago/reachable.py` (ArchiLAN-dev/archilan-archipelago PR 39)
- `archipelago/tests/test_exact_regeneration.py`
- `archipelago/tests/test_session_daemon.py`
- `archipelago/tests/test_slot_data_replay.py`

## Change Log

| Date | Changement |
|---|---|
| 2026-10-17 | Créée et implémentée (signalement de Jean, partie Pikmin 2). Status: review. |
