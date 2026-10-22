# Story 38.19 - Les apworlds zippés sous Windows passent l'introspection

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-22
**Origine :** mise en prod v0.44.0 (2026-10-22) - `app:games:backfill-option-types --reintrospect` échoue pour fez
(`bd601a9e…`), dungeon_clawler (`4e6f4bb2…`) et nrftw (`e1689e99…`) sur `ModuleNotFoundError: No module named
'worlds.<pkg>'`.
**Dépôts :** `archipelago` (correctif, nouvelle image, ArchiLAN-dev/archilan-archipelago#41), `archilan.fr` (cette story, notes de mise en prod).

## Constat

Les trois archives ont été construites sous Windows : leurs entrées utilisent `\` comme séparateur
(`fez\__init__.py`, `dungeon_clawler\constants\enemies.py`). Sous Linux, `ZipFile.extractall` ne convertit pas `\`
(seul `os.sep` l'est) : il écrit des fichiers à plat nommés littéralement `fez\__init__.py` à la racine du dossier
temporaire. Le log de prod le montre : `extracted root holds: ['fez\\CHANGELOG.md', 'fez\\Items.py', ...]` et
`/tmp/apworld_.../fez/__init__.py exists: False`.

`_detect_pkg` normalise déjà les `\` pour trouver le paquet, mais l'extraction de `introspect_options.py`
(ligne 281, `_zf.extractall(_tmp_dir)`) ne le fait pas. Les trois autres chemins d'extraction le font déjà, membre
par membre (`member.filename = member.filename.replace("\\", "/")`) :

- `generate_multiworld.py` (génération des parties),
- `generate_template.py` (template par défaut),
- `reachable.py` (suivi exact).

La génération de ces jeux marche donc ; seule l'introspection (options, lieux, objets) échoue, et ces jeux gardent
des types d'options, des lieux et des objets périmés ou vides.

Le diagnostic écrit jusqu'ici est faux : la docstring de `_detect_pkg` et celle de `tests/test_detect_pkg.py`
expliquent ces trois échecs par un paquet « imbriqué un niveau plus bas ». Ce n'est pas le cas : le paquet est à la
racine, ce sont les séparateurs qui posent problème.

## Critères d'acceptation

1. `introspect_options.py` extrait chaque membre avec ses `\` remplacés par `/`, comme les trois autres scripts.
   fez, dungeon_clawler et nrftw s'introspectent (options, lieux, objets) dans l'image.
2. L'extraction reste confinée au dossier temporaire : un membre normalisé qui sortirait du dossier (`..\`,
   chemin absolu) est refusé, comme le fait déjà `ZipFile.extract`.
3. Un test construit une archive aux séparateurs `\` et vérifie que l'introspection la charge (pas seulement que
   `_detect_pkg` trouve le nom).
4. Un test de parité échoue si un script du dépôt extrait une archive apworld sans normaliser les séparateurs
   (garde-fou contre le prochain `extractall`).
5. Les docstrings de `_detect_pkg` et de `tests/test_detect_pkg.py` décrivent la vraie cause.
6. Notes de mise en prod (`CHANGELOG.md` de la prochaine release) : nouvelle image archipelago, puis
   `app:games:backfill-option-types --reintrospect` et `app:games:backfill-locations` (story 38.20) pour rattraper
   les trois jeux.

## Tâches

- [x] `archipelago` : extraction normalisée dans `introspect_options.py` (même code que les autres scripts, isolé
  dans `_extract_apworld`).
- [x] `archipelago` : tests (archive `\`, parité des extractions), docstrings.
- [x] Vérification réelle dans l'image reconstruite (archive synthétique, voir Dev Agent Record).
- [ ] `archilan.fr` : ligne de mise en prod dans le CHANGELOG à la release.

## Hors périmètre

- sm_map_rando (`ef580223…`) : il installe `pysmmaprando` depuis github.com à l'import, impossible dans un conteneur
  sans réseau (choix posé en 38.17). Il échoue aussi à la génération. À traiter à part (roue embarquée dans l'image,
  sur le modèle de la liste Beat Saber).

## Dev Agent Record

### Completion Notes

- `introspect_options.py` : `_extract_apworld` extrait membre par membre avec `\` -> `/`, comme
  `generate_multiworld.py`, `generate_template.py` et `reachable.py`. Pas de module partagé : `reachable.py` ne
  dépend pas de `apworld_import`, et le garde-fou de parité empêche la divergence.
- AC2 : `ZipFile.extract` assainit le nom normalisé (`..`, racine) ; testé sur `..\x`, `\x` et `pkg\..\..\x`.
- AC4 : `tests/test_apworld_extraction.py` échoue si un script du dépôt appelle `extractall`, ou un `.extract(` sans
  normalisation juste avant. Vérifié : avec l'ancien `introspect_options.py`, le fichier de tests échoue.
- Vérification réelle : les trois apworlds de prod ne sont pas en local. Copie de ChecksFinder renommée
  (`checksfinder_win`, jeu « ChecksFinder Win ») et zippée avec des `\`, introspectée dans un conteneur
  `--network none` :
  - image `archipelago:story-38-19` : sortie 0, options, 25 lieux, objets ;
  - image actuelle : `No module named 'worlds.checksfinder_win'`, `__init__.py exists: False`, la signature exacte
    du log de prod.
- `pytest` : 213 verts.

### File List

- `archipelago/introspect_options.py`
- `archipelago/reachable.py` (docstring)
- `archipelago/tests/test_detect_pkg.py` (docstrings)
- `archipelago/tests/test_apworld_extraction.py` (nouveau)
