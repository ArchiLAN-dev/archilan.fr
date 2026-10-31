# Story 38.29 - Un apworld passe devant le monde intégré du même nom

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-31
**Origine :** The Legend Zelda: Ocarina of Time (updated, not Ship), mise à jour rejetée (candidat release7 de
`TreZc0/OoTR-APWorld`, hash `21b25e8f8038`), incident « YAML illisible » du 2026-10-22 : `Settings reports required
version of world "Ocarina of Time" is at least 9.1.0, however world is of version 7.0.0`. Remonté par Jean le
2026-10-31, reproduit ici.
**Dépôts :** `archipelago` (correctif, PR #47, nouvelle image), `archilan.fr` (cette story).

## Constat

L'apworld OoT 9.1 livre son paquet sous le nom `oot`, celui du monde OoT 7.0 intégré à la release (l'auteur demande
aux joueurs de supprimer le monde du core). Nos scripts ne le chargent pas au même rang :

- `generate_template.py` et `introspect_options.py` insèrent l'apworld en tête de `worlds.__path__` : la 9.1 gagne,
  le template porte `requires: Ocarina of Time: 9.1.0` ;
- `generate_multiworld.py` et `reachable.py` l'ajoutent en fin : le dossier `oot` de la release passe devant, la
  génération charge la 7.0 et refuse le YAML.

La version en ligne (release6) a le même nom de paquet : les parties OoT générées jusqu'ici l'ont sans doute été avec
le monde 7.0, et non la 9.1 que les joueurs installent.

## Décision

Les quatre scripts insèrent l'apworld en tête de `worlds.__path__`. Sans apworld homonyme, rien ne change.

## Critères d'acceptation

1. `generate_multiworld.py` et `reachable.py` insèrent l'apworld en tête, comme le template et l'introspection.
2. Un test échoue si un script ajoute un apworld en fin de `worlds.__path__`.
3. Docker, release7 avec `requires: 9.1.0` : génération réussie, là où `archipelago:latest` reproduit l'erreur.
4. `pytest` vert.

## Tâches

- [x] `generate_multiworld.py`, `reachable.py` : `__path__.insert(0, ...)`.
- [x] `tests/test_apworld_precedence.py`.
- [x] Vérification Docker.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes

- `tests/test_apworld_precedence.py` : les quatre scripts insèrent en tête et aucun n'ajoute en fin (2 échecs sans
  le correctif), plus une démonstration de la précédence d'un paquet inséré en tête.
- `pytest` 228 passed (branche partie de `master`).
- Docker, release7 (sha256 `21b25e8f...`, celui de l'incident) :
  - `archipelago:latest` : `required version ... is at least 9.1.0, however world is of version 7.0.0` ;
  - image de la branche : génération réussie.

### Mise en prod

1. Merger la PR `archipelago` #47, publier l'image.
2. OoT (updated, not Ship) : relancer le test du candidat (story 38.14 ; la ROM via 38.28 si le test la réclame),
   puis résoudre l'incident. Les parties OoT générées après le déploiement le seront en 9.1.

### File List (dépôt archipelago)

- `generate_multiworld.py`
- `reachable.py`
- `tests/test_apworld_precedence.py`
