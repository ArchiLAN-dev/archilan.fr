# Story 41.18: Tirage plus malin et objectifs ciblés

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant qu'admin,
je veux que le tirage varie d'une semaine à l'autre et que certaines quêtes sortent plus souvent, et je veux des
objectifs sur un jeu ou un événement précis,
afin que les quêtes collent à ce qui se joue sur le site.

## Contexte

Troisième des améliorations du système de quêtes proposées après la 41.15 (« Go faire tes propals », Jean,
2026-10-06).

## Critères d'acceptation

1. **Pas deux fois de suite** : le tirage d'une semaine évite les quêtes servies la semaine précédente ; il n'en
   reprend que s'il manque de quêtes pour remplir la semaine.
2. **Poids** : chaque quête a un poids de tirage de 1 à 5 (1 par défaut) : à poids 3, elle a trois fois plus de
   chances de sortir qu'une quête à poids 1. Réglable dans le formulaire, affiché dans la liste des types.
3. **Objectifs ciblés** : un objectif « Goals atteints », « Checks faits » ou « Parties jouées » peut viser un jeu
   (joué sur le site) ou un événement : il ne compte alors que les sessions de ce jeu ou de cet événement (les
   hebdos n'y comptent pas). Un même type peut revenir dans une quête s'il vise des cibles différentes.
4. **Affichage** : l'objectif ciblé est nommé partout (« Goals atteints · Hollow Knight », « 1 goal sur Hollow
   Knight ») ; la barre de progression du membre suit la cible.
5. **Validation** : une cible inconnue (jeu jamais joué sur le site, événement inexistant ou en brouillon) est
   refusée ; une cible sur un type qui ne la prend pas aussi.
6. Gates verts ; tests du tirage (pas de répétition, repli, poids), des objectifs ciblés (comptage, validation)
   et des écrans.

## Notes techniques

- `QuestObjective` gagne une cible facultative (`game` ou `event`) et une clé (`checks`, `checks@game:{id}`) qui
  indexe les compteurs à la place du type seul.
- Le tirage pondéré sans remise reste une fonction pure (`QuestDraw`) avec le générateur injecté.
- Migration : colonne `draw_weight` (1 par défaut) sur `quest_definition`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Poids et tirage ; migration ; tests.
- [x] **Task 2** (AC 3, 5) - Objectifs ciblés : domaine, compteurs, validation ; tests.
- [x] **Task 3** (AC 4) - Noms des cibles (API membre et admin) ; formulaire et affichages ; tests.
- [x] **Task 4** (AC 6) - Gates, vérification dans l'app.

## Dev Agent Record

### Notes

- Tirage pondéré sans remise : à chaque pioche, une quête sort avec la probabilité de son poids sur la somme des
  poids restants. Les quêtes de la semaine précédente forment un second panier, pioché seulement si le premier ne
  suffit pas.
- Cible d'un objectif : `scope` (`game` | `event`) et `scopeId` stockés dans le JSON des objectifs ; compteurs
  indexés par `QuestObjective::key()`. Un objectif ciblé ne compte que les sessions (`session_slot.game_id`, ou
  `session.event_id`), jamais les hebdos.
- Les jeux proposés sont ceux joués au moins une fois sur le site (`session_slot`), les événements ceux sortis du
  brouillon ; une cible disparue s'affiche « un jeu retiré » / « cible retirée ».
- `drawWeight` absent d'une requête vaut 1 (compatibilité) ; donné mais pas un nombre, il est refusé.

### File List

- `api/migrations/Version20261006100000.php`
- `api/src/Wallet/Domain/` : `Entity/QuestDefinition.php`, `ValueObject/QuestObjective.php`, `Service/QuestDraw.php`
- `api/src/Wallet/Application/` : `Command/AwardWeeklyQuests.php`, `Command/ManageQuests.php`,
  `Query/MyWeeklyQuests.php`, `Query/QuestAdminQuery.php`, `Query/WeeklyQuestsQueryInterface.php`,
  `Service/QuestWeekPlanner.php`
- `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php`, `api/src/Wallet/Presentation/Controller/AdminQuestController.php`
- `api/tests/` : `Unit/Wallet/QuestDefinitionTest.php`, `Functional/WeeklyQuestsTest.php`, `Functional/AdminQuestTest.php`
- `frontend/src/features/wallet/` : `admin-quests-api.ts`, `admin-quests-page.tsx` (+ test), `admin-quest-weeks.tsx`,
  `weekly-quests.tsx` (+ test)
