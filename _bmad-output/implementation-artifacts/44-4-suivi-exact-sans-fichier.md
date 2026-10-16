# Story 44.4 - Le suivi exact d'une partie à ROM, sans la ROM

**Status:** review
**Epic:** 44 - Fichiers requis à la génération (ROM fournie par le joueur)
**Date:** 2026-10-16
**Dépend de:** 44.1 (`no_gui`, `required_file_record`), 44.2/44.3/44.5

## Epic 44 en bref

On ne stocke jamais de ROM : le lanceur la fournit pour une génération, puis elle est supprimée. Le suivi exact
(`reachable.py`, mémoire « Suivi exact (reachable) ») **régénère** la partie avec la seed du spoiler pour calculer
ce qui est atteignable, des semaines après le lancement : la ROM n'est plus là.

## Constat (vérifié le 2026-10-16)

- `reachable.py` rejoue `Generate.main` puis `generate_early` → `create_regions` → `create_items` → `set_rules` →
  `connect_entrances` → `generate_basic`. Il n'appelle **ni** `stage_assert_generate` (où ALttP, OoT, Yoshi's Island
  vérifient leur ROM) **ni** `generate_output` (où tous lisent la ROM pour le patch).
- **DKC3, vérifié de bout en bout** : partie générée avec la ROM (44.2), puis `reachable.py --slot 2` sans la ROM ni
  aucun fichier : chemin exact réussi (aucun repli), 180 lieux, 45 atteignables, 5 sphères.
- **ALttP lit la ROM pendant `create_items`** quand le mélange des ennemis est actif :
  `ItemPool` → `generate_enemy_shuffle_state` → `_get_base_patched_rom_bytes()` (`worlds/alttp/EnemyShuffle.py:264,320`)
  lit `get_base_rom_path()`. La logique dépend alors des données de la ROM : sans elle, ni le chemin exact ni le
  repli « joueur seul » (qui régénère le même jeu) ne peuvent aboutir. Aujourd'hui l'échec est muet côté joueur.
- Les autres jeux bloqués (Mario Kart 64, Paper Mario, Shining in the Darkness, Wario Land, Yoshi's Island, OoT) ne
  sont pas vérifiés un par un.

## Story

En tant que joueur d'une partie dont un jeu a demandé une ROM,
je veux que le suivi d'atteignabilité marche sans elle quand c'est possible, et me dise clairement quand ce n'est pas
possible,
afin de ne pas voir un suivi vide ou faux sans explication.

## Critères d'acceptation

1. **Garde-fou** : un test (AST, comme `test_generation_setup.py`) vérifie que `reachable.py` n'appelle jamais
   `assert_generate` ni `generate_output` (ni `Main.main`) : ce sont les étapes qui lisent la ROM chez presque tous les
   worlds.
2. **Inventaire** : pour chacun des 8 jeux bloqués (et tout jeu marqué `neededAtGeneration` sur la base), lancer le
   suivi exact sans fichier sur une partie générée avec le fichier, ou à défaut relever statiquement dans quelle
   étape le world lit sa ROM. Résultat consigné dans le Dev Agent Record (jeu, étape, OK / KO).
3. **Échec nommé** : quand le suivi échoue parce qu'un world lit son fichier (le `FileNotFoundError` « ... is
   required » de `no_gui`, ou une erreur d'ouverture du chemin du réglage), `reachable.py` rend
   `{"error": ..., "requiredFile": {setting, file, description}}` (même enregistrement que 44.1, via
   `required_file_record`) au lieu d'un repli qui échouera aussi.
4. **Affichage** : le bridge transmet cette erreur, et l'écran de suivi du joueur affiche « Le suivi de ce jeu n'est
   pas disponible : il a besoin de la ROM (« <libellé> »), qu'ArchiLAN ne conserve pas. » au lieu d'un suivi vide.
   Les autres slots de la partie restent suivis normalement.
5. Tests : pytest (garde-fou, enregistrement de l'échec nommé) ; bridge et front selon l'endroit où l'erreur est
   relayée ; gates verts des dépôts touchés.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Test AST du garde-fou dans `archipelago/tests/`.
- [x] **Task 2** (AC 2) - Inventaire des 8 jeux ; Dev Agent Record.
- [x] **Task 3** (AC 3) - `reachable.py` : échec nommé (exact et repli), sans nouveau repli inutile.
- [x] **Task 4** (AC 4) - Bridge (`bridge/core/reachable.py`) et front : relais et message.
- [x] **Task 5** (AC 5) - Tests et gates.

## Dev Notes

- Lancer `reachable.py` à la main sur une session : monter le volume `archilan_session_{id}` sur `/data`, copier
  `/data/worlds/*.apworld` dans `/apworlds`, extraire le `.archipelago` du zip de sortie, puis
  `REACHABLE_STATE_JSON='{"checked": [], "items": []}' python3 /reachable/reachable.py --archipelago <f> --yamls
  /data/yamls --slot <n>` (le slot 1 est l'observateur `Bridge` tant que la 9.56 n'est pas faite).
- Ne **pas** chercher à rendre la ROM disponible au suivi : elle n'est jamais stockée (décision de l'epic).
- Piste écartée : neutraliser le mélange des ennemis d'ALttP pendant le suivi. La logique en dépend, le suivi serait
  faux.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5 (1M context)

### Completion Notes List

- **Image** (`archipelago`, branche `feature/epic-44-story-4-suivi-sans-fichier`, commit 9d80dab) : dans le repli
  « joueur seul », un `FileNotFoundError` « ... is required » est reconnu par `required_file_record` et levé en
  `RequiredFileUnavailable` ; `_error_payload` rend `{"error": <message>, "requiredFile": <record>}` (démon et
  one-shot). Message : « Le suivi de ce jeu n'est pas disponible : il a besoin du fichier « <libellé> », qu'ArchiLAN
  ne conserve pas après la génération. » Les autres slots restent suivis.
- **Bridge, API, front inchangés** : le bridge relaie le texte `error` (HTTP 500 `detail`), l'API le rend en
  `bridge_error` avec ce message, et la page de slot affiche `error.message`. Le message lisible arrive donc tel quel
  au joueur.
- **Inventaire (AC 2)** :

  | Jeu | Où la ROM est lue | Suivi sans ROM |
  |---|---|---|
  | Donkey Kong Country 3 | `stage_assert_generate`, `generate_output` | OK, vérifié en réel (exact, 180 lieux, 5 sphères) |
  | Mario Kart 64 | `stage_assert_generate`, `generate_output` | OK (statique) |
  | Shining in the Darkness | `stage_assert_generate` | OK (statique) |
  | Wario Land 1 | `stage_assert_generate`, `generate_output` | OK (statique) |
  | Ocarina of Time | `stage_assert_generate`, `generate_output` | OK (statique) |
  | Yoshi's Island | `stage_assert_generate`, `generate_output` | OK (statique) |
  | A Link to the Past | idem, **et `create_items` avec le mélange des ennemis** (`EnemyShuffle.py:264`) | KO avec l'option : message nommé |
  | Paper Mario | absent du catalogue de dev | non vérifié |

- Tests : `tests/test_reachable_required_file.py` (garde-fou AST : ni `assert_generate`, ni `generate_output`, ni
  `Main.main`), `tests/test_session_daemon.py` (slot à ROM nommé, les autres suivis, `_error_payload`). pytest 172
  verts.
- Non vérifié en réel : le message sur un vrai ALttP avec mélange des ennemis (pas de ROM ALttP sous la main) ;
  couvert par le test unitaire sur le vrai `required_file_record`.
- Limite connue : quand un jeu à ROM fait échouer la régénération exacte, **tous** les slots de la partie passent au
  repli « joueur seul » (moins précis). Inévitable sans la ROM.

### File List

- archipelago : `reachable.py`, `tests/test_reachable_required_file.py`, `tests/test_session_daemon.py`
