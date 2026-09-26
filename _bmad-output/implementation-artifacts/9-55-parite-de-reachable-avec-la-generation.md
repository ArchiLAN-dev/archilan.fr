# Story 9.55: Le suivi prépare Archipelago comme la génération

**Status:** review
**Epic:** 9 - Multiworld generation pipeline & apworld introspection
**Date:** 2026-09-26
**Vient de :** revue de la PR archipelago #27 (suivi exact avec la vraie seed), qui a relevé que `reachable.py` ne
prépare pas Archipelago comme `generate_multiworld.py`.

## Story

En tant que joueur d'une run qui contient un monde ou une option particuliers,
je veux que le suivi (« ce qui est faisable ») marche dès que la run a pu être générée,
afin de ne pas me retrouver sans suivi sur une partie pourtant lancée.

## Contexte

`generate_multiworld.py` prépare Archipelago avec deux mécanismes que `reachable.py` n'a jamais eus :

- **la métaclasse `Choice` permissive** : rune4, smash64 et untitled_goose_game définissent `option_random`, un nom
  réservé par Archipelago ; sans le correctif, ces mondes ne se chargent pas ;
- **les réglages d'hôte** (`allow_*` / `enable_*`, story 27.11) : une option joueur protégée par un réglage d'hôte
  fait lever Generate tant que le réglage n'est pas ouvert.

`reachable.py` lance Generate sur tous les YAML de la session, sur ses deux chemins (exact et repli) : une run avec
l'un de ces mondes ou de ces options était générée, puis n'avait **aucun suivi**. Les copies du correctif `Choice`
vivaient dans trois scripts, celles des réglages d'hôte dans un seul ; la mémoire du projet notait déjà qu'un
troisième filet de parité poserait la vraie question - pourquoi dupliquer au lieu de partager.

## Critères d'acceptation

1. **Un seul module** `generation_setup.py` porte la préparation partagée : `install_permissive_choice_meta()`
   (idempotent), `derive_host_gate_settings`, `write_host_gate_yaml` et `apply_host_gates` (écrit `host.yaml` puis
   vide le cache de `settings.get_settings`, sans quoi un monde qui aurait lu les réglages avant garderait les
   réglages fermés).
2. **Les quatre scripts** qui chargent des apworlds l'appellent (`generate_template.py`, `introspect_options.py`,
   `generate_multiworld.py`, `reachable.py`) et n'en gardent aucune copie ; les deux qui génèrent ouvrent les
   réglages d'hôte.
3. **La génération de prod est inchangée** : même seed, mêmes placements, sphères et inventaires de départ.
4. **L'image** copie le module, normalise ses fins de ligne et l'importe au build (garde-fou contre un COPY oublié).
5. Tests du dépôt archipelago verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `generation_setup.py` et ses tests.
- [x] **Task 2** (AC 2) - Les quatre scripts branchés ; test de parité par AST.
- [x] **Task 3** (AC 4) - Dockerfile.
- [x] **Task 4** (AC 3, 5) - Vérifications.

## Dev Agent Record

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `test_generation_setup.py` : correctif `Choice` (retire `option_random`, relaie toute autre assertion, idempotent), réglages d'hôte (écriture, fusion, cache vidé, rien à faire) | module absent | 7 verts |
| Parité : les 4 scripts arment le correctif, aucun n'en garde de copie, les 2 scripts qui génèrent ouvrent les réglages | 9 échecs | verts |

`test_worlds_stub_parity.py` ne vérifie plus que `_worlds_getattr` (propre aux scripts à bouchon `worlds`) ; le
correctif `Choice` est gardé par le nouveau test.

### Vérifications (image construite localement)

- **Génération de prod inchangée** : la run réelle de 10 joueurs regénérée avec sa seed dans la nouvelle image donne
  des placements, sphères et inventaires de départ identiques à l'original.
- **Réglage d'hôte** : seed Buckshot Roulette avec `consumable_item_logic: none` (exige `allow_no_consumable_item_logic`).
  Avant, reachable v0.16.2 : `allow_no_consumable_item_logic is disabled in your host.yaml`, aucun suivi. Après :
  77 lieux atteignables sur 80, par le chemin exact.
- **Non-régression du suivi** : slot Hollow Knight de la run réelle, mêmes résultats qu'en v0.16.2.
- 123 tests verts.
