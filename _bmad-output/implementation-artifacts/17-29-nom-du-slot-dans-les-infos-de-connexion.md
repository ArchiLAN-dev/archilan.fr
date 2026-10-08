# Story 17.29: Le nom du slot en tête des infos de connexion

**Status:** review
**Epic:** 17 - Sessions
**Date:** 2026-10-08

## Story

En tant que joueur d'une hebdo, d'une partie privée ou d'un événement,
je veux voir mon nom de slot clairement, en premier, dans les infos de connexion,
afin de le taper sans le chercher : c'est la première chose que mon client Archipelago demande.

## Contexte

Demande de Jean (2026-10-08) : « le nom du slot est une info de connexion importante, on ne le voit pas assez
clairement ». État des lieux :

- **Partie privée** : absent du bloc « Infos de connexion » (on ne le trouvait que dans la grille de progression).
- **Hebdo** (carte et page du slot) : absent du bloc « Serveur Archipelago prêt ».
- **Événement** : présent, mais en petite ligne « Slot : … » sous le nom du jeu, dans « Tes créneaux », sous la
  carte de connexion.

L'aide du bloc de connexion mentionnait déjà « ton nom de slot » sans jamais l'afficher.

Sources du nom :

- Partie privée et événement : `session_slot.slot_name`, le nom réellement attribué au lancement.
- Hebdo : le `name:` du YAML du template, tel qu'Archipelago le résout dans un monde à un joueur
  (`Player{number}` devient `Player1`), comme `handle_name` dans Generate.py.

## Critères d'acceptation

1. Le bloc de connexion (`ConnectionFields`, partagé par les trois surfaces) affiche le nom du slot **en premier**,
   en grand, **en clair** (ce n'est pas un secret : le jeu et le tracker l'affichent) et copiable.
2. Plusieurs slots (partie privée multi-jeux, co-joueur, événement) : un champ par slot, libellé avec son jeu.
   Un seul slot : pas de jeu répété.
3. Partie privée : la run active expose `mySlots` - les slots que l'appelant possède et ceux qu'il co-joue,
   jamais ceux des autres. Vide hors run active. Slot d'une seed importée : jeu repris de l'archive.
4. Hebdo : `myEntry.slotName` = nom du YAML du template résolu (placeholders `{number}`, `{player}`,
   `{NUMBER}`, `{PLAYER}`, `%number%`, `%player%`, 16 caractères max, `Player{number}` par défaut).
5. Événement : la carte de connexion affiche les créneaux du joueur, et « Tout copier » commence par le nom du slot.

## Tâches

- [x] **Task 1** (AC 4) - `ArchipelagoSlotNameResolver` (Shared) + `DbalCurrentWeeklyRunsQuery` (`slotName`).
- [x] **Task 2** (AC 3) - `MyRunSlotsQueryInterface` / `DbalMyRunSlotsQuery` (via `DbalSlotPlayerSource`, co-joueurs
  compris), `PersonalRunDrafts::payload()` (`mySlots`).
- [x] **Task 3** (AC 1, 2) - `SlotNameField`, `ConnectionFields` (`slots`), `ConnectionDetails`.
- [x] **Task 4** (AC 1, 5) - branchement : page de partie privée (propriétaire et participant), carte et page de
  slot hebdo, carte de connexion d'événement (+ « Tout copier »).
- [x] **Task 5** - tests : `ArchipelagoSlotNameResolverTest`, `PersonalRunMySlotsTest`, assertion dans
  `CurrentWeeklyRunsTest`, `connection-fields.test.tsx`.
- [x] **Task 6** - `composer gates` et `pnpm gates` verts.

## Dev Notes

- Le nom de l'hebdo est déduit du YAML, pas lu dans le monde généré : si Archipelago changeait sa résolution, il
  faudrait suivre. Le cas courant (`Player{number}`) et les noms littéraux sont couverts par les tests.
- Pas vérifié visuellement dans l'app : aucune hebdo ni partie active en local au moment du développement.

## Dev Agent Record

### File List

- `api/src/Shared/Application/Support/ArchipelagoSlotNameResolver.php`
- `api/src/WeeklyRuns/Infrastructure/Dbal/DbalCurrentWeeklyRunsQuery.php`
- `api/src/PersonalRuns/Application/Query/MyRunSlotsQueryInterface.php`
- `api/src/PersonalRuns/Infrastructure/Dbal/DbalMyRunSlotsQuery.php`
- `api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php`
- `api/config/services.yaml`
- `api/tests/Unit/Shared/ArchipelagoSlotNameResolverTest.php`
- `api/tests/Functional/PersonalRunMySlotsTest.php`
- `api/tests/Functional/CurrentWeeklyRunsTest.php`
- `api/tests/Unit/PersonalRuns/PersonalRunDraftsGetTest.php`
- `api/tests/Unit/PersonalRuns/PersonalRunDraftsListMineTest.php`
- `frontend/src/components/slot-name-field.tsx`
- `frontend/src/components/connection-fields.tsx`
- `frontend/src/components/connection-fields.test.tsx`
- `frontend/src/features/personal-runs/connection-details.tsx`
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx`
- `frontend/src/features/personal-runs/types.ts`
- `frontend/src/features/weekly-runs/weekly-runs-api.ts`
- `frontend/src/features/weekly-runs/weekly-run-card.tsx`
- `frontend/src/features/weekly-runs/weekly-run-slot-page.tsx`
- `frontend/src/features/events/session-connection-gate.tsx`
