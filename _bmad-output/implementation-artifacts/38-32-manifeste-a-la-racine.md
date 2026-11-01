# Story 38.32 - Le manifeste d'un apworld est lu à la racine du zip

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-11-01
**Origine :** Dungeon Defenders, mise à jour rejetée (v0.8.2 de `Haywire32/DD1-AP`, hash `b6857d410bfa`), incident
« YAML illisible » du 2026-11-01 : `Settings reports required version of world "Dungeon Defenders" is at least
0.8.2, however world is of version 0.0.0`. Remonté par Jean, reproduit ici.
**Dépôts :** `archipelago` (correctif, nouvelle image), `archilan.fr` (cette story).

## Constat

- L'apworld remplace le template généré par son YAML d'auteur (`public_yaml.py`, enveloppe autour de
  `Options.generate_yaml_templates`). Ce YAML porte `requires: game: Dungeon Defenders: 0.8.2`.
- Son `archipelago.json` (`world_version: 0.8.2`) est à la racine du zip, pas dans le dossier `dungeon_defenders/`.
- Archipelago lit le manifeste d'un apworld d'abord à la racine du zip (`worlds/Files.py`,
  `manifest_path = "archipelago.json"`), puis n'importe où dedans. Notre `apworld_import._read_manifest` ne fouille
  que le dossier du paquet : le world reste en 0.0.0 et `Generate.py` refuse le YAML.
- Tous nos scripts sont touchés (template, introspection, génération, reachable) : seul le refus est visible, à la
  génération.

## Décision

`_read_manifest` regarde d'abord le dossier qui contient le paquet (la racine du zip extrait), puis le paquet, comme
le core. Pour un world du dossier `worlds/` de la release, ce dossier parent n'a pas de manifeste : rien ne change.

## Critères d'acceptation

1. Un apworld dont `archipelago.json` est à la racine du zip reçoit sa `world_version`.
2. Le manifeste dans le paquet continue de fonctionner ; à la racine et dans le paquet, la racine gagne (ordre du core).
3. Docker, DD1 v0.8.2 avec son YAML d'auteur : génération réussie, là où `archipelago:latest` reproduit l'erreur.
4. `pytest` vert.

## Tâches

- [x] `apworld_import._read_manifest` : racine du zip extrait d'abord.
- [x] Tests dans `tests/test_apworld_import.py`.
- [x] Vérification Docker.

## Mise en prod

Image archipelago, puis « Relancer le test » du candidat Dungeon Defenders v0.8.2.

## Dev Agent Record

- archipelago PR #50 (branche `fix/story-38-32-manifeste-a-la-racine`) : `_read_manifest` lit d'abord `archipelago.json` dans le dossier qui contient le paquet ; 2 tests (echouent sans le correctif) ; `pytest` 242 verts.
- Docker (`archipelago:story-38-32`) : template DD1 v0.8.2 avec `requires 0.8.2`, generation reussie ; `archipelago:latest` reproduit l'erreur.
