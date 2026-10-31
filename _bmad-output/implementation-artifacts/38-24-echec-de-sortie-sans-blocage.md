# Story 38.24 - Un échec de sortie ne bloque plus la génération

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-31
**Origine :** « Ocarina of Time but it's just Master Quest Water Temple » (apworld `ootbijmqwt`, hash `1a930c4ed5b6`)
a un incident « Génération trop longue » ouvert depuis le 2026-09-27 (7 occurrences) : `preflight timed out after 5m0s`.
Remonté par Jean le 2026-10-31, reproduit ici.
**Dépôts :** `archipelago` (correctif, nouvelle image), `archilan.fr` (cette story).

## Constat

Ce n'est pas une génération lente : c'est un blocage, toujours au même endroit, et c'est un fichier manquant.

- `ootbijmqwt` hérite de `OOTWorld` et remplace sa `stage_assert_generate`, celle qui ouvre la ROM d'OoT avant
  tout le reste. Le world n'échoue donc pas d'entrée sur la ROM absente, comme Ocarina of Time (epic 44).
- `OOTWorld.generate_output` ouvre la ROM (`Rom(file=oot_settings.rom_file)`) **avant** son `try/finally` qui pose
  l'événement `collectible_flags_available`. Sans ROM, la méthode lève et l'événement n'est jamais posé.
- `OOTWorld.fill_slot_data` attend cet événement sans délai (`self.collectible_flags_available.wait()`).
  `Main.main` l'appelle dans `write_multidata`, dans le même pool que les sorties ; la sortie du `with` attend
  toutes les tâches. Le processus reste bloqué jusqu'au délai de 5 minutes de l'orchestrateur.

Reproduit avec `archipelago:latest` et les options par défaut : aucune sortie après les gates d'hôte ;
`faulthandler` montre `write_multidata` bloqué dans `threading.Event.wait` (Main.py:280), le thread principal dans
`ThreadPoolExecutor.__exit__`.

Le défaut est dans OoT d'Archipelago, mais tout world qui fait attendre `fill_slot_data` (ou une autre étape) sur un
`threading.Event` posé par sa sortie a le même piège. L'API, elle, sait déjà gérer la suite : une erreur
`FileNotFoundError ... OOTSettings.rom_file is required` devient un fichier requis constaté
(`Game::markRequiredAtGeneration`, story 44.3), même non déclaré.

## Décision

Quand `generate_output` d'un world échoue, notre script pose tous les `threading.Event` de ce world avant de
relancer l'erreur. Ceux qui attendaient repartent, l'erreur d'origine sort par `future.result()` (elle termine
avant `write_multidata`, `as_completed` la rend la première) et la génération échoue en quelques secondes avec
la vraie cause. Correctif côté image, partagé dans `generation_setup.py`, plutôt qu'un apworld modifié.

## Critères d'acceptation

1. Un `generate_output` qui lève pose les `threading.Event` du world concerné, puis l'erreur d'origine est
   relancée telle quelle (type, message, notes PEP 678).
2. Un `generate_output` qui réussit ne touche à rien ; les autres étapes ne sont pas concernées.
3. L'installation est idempotente.
4. Docker, `ootbijmqwt` sans ROM : échec en quelques secondes avec la ligne `###ARCHILAN-FAILURE###` qui nomme
   `OOTSettings.rom_file` (fichier requis), plus de délai dépassé.
5. Docker, avec la ROM fournie (`--required_file OOTSettings.rom_file=...`) : la génération aboutit.
6. Tests unitaires ; `pytest` vert.

## Tâches

- [x] `generation_setup.py` : `release_events_on_output_failure()`, branché dans `generate_multiworld.py`.
- [x] Tests `tests/test_output_failure_release.py`.
- [x] Vérification Docker sans et avec ROM.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes

- `release_events_on_output_failure()` enveloppe `worlds.AutoWorld.call_single` (que `Main.main` appelle pour chaque
  `generate_output`) : sur une exception de `generate_output`, chaque `threading.Event` du world est posé, puis
  l'exception d'origine est relancée (même objet, notes PEP 678 comprises). Seul `generate_output` est concerné.
- Tests : 4 nouveaux, rouges sans le correctif ; `pytest` 227 passed (Python 3.10 du poste).
- Docker, `ootbijmqwt` hash `1a930c4e...`, options par défaut :
  - `archipelago:latest` : bloqué, tué après 150 s (pile `write_multidata` / `Event.wait`) ;
  - image de la branche sans ROM : échec en 17 s, `###ARCHILAN-FAILURE###` avec `requiredFile`
    `OOTSettings.rom_file` (description et MD5 d'OoT) ;
  - image de la branche avec la ROM (`--required_file OOTSettings.rom_file=...`) : génération réussie en 22 s.

### Mise en prod

1. Merger la PR `archipelago`, publier l'image.
2. Fiche admin du jeu : relancer le test de l'apworld. L'échec « fichier requis » marque la ROM d'OoT comme
   nécessaire à la génération (story 44.3), le lanceur devra la fournir.
3. Pour un verdict vert : relancer le test en fournissant la ROM (story 44.6), puis clore l'incident « Génération
   trop longue ».

### File List (dépôt archipelago)

- `generation_setup.py`
- `generate_multiworld.py`
- `tests/test_output_failure_release.py` (nouveau)
