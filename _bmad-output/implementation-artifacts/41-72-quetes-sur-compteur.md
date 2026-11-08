# Story 41.72: Objectifs de quête sur un compteur de checks ou d'items

**Status:** ready-for-dev
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-08

## Story

En tant qu'admin,
je veux qu'un objectif de quête hebdo porte sur un compteur de checks ou d'items (story 30.57),
afin de proposer des quêtes concrètes (« cette semaine, battez Morpha », « 20 Gold Skulltula Tokens ») et pas
seulement des volumes de checks par jeu.

## Contexte

Suite de la 30.57 et dépend d'elle : la 30.57 crée les compteurs admin (jeu du catalogue, nature `location` / `item`,
noms tirés de l'introspection de l'apworld et filtrés sur la saisie, option « avec un autre membre », mode) et leur
comptage sur `session_feed_event`. Cette story les branche sur les quêtes hebdo de la 41.15 / 41.18 sans recréer de
sélecteur ni de règle de comptage. Décision de Jean du 2026-11-08 : les quêtes passent dans une story à part.

## Critères d'acceptation

1. **Nouvelle métrique** `QuestMetric::Counter`, « Compteur de checks ou d'items », avec la portée `counter`
   (`scopeId` = id du compteur). Elle n'accepte que cette portée, et la portée est obligatoire.
2. **Formulaire admin des quêtes** : choisir « Compteur » affiche la liste des compteurs (libellé, jeu) et un lien
   pour en créer un dans l'admin des succès. Le libellé et l'unité de la jauge viennent du compteur
   (« 3 / 20 · Gold Skulltula Tokens »).
3. **Comptage sur la semaine** : mêmes règles que la 30.57 (jeu du compteur, slot que le membre joue, hebdos
   comprises sauf option « avec un autre membre », release et collect exclus, une location une fois par slot),
   limitées aux lignes du feed dont `occurred_at` tombe dans la semaine. Modes : `total` et `distinct` sur la
   semaine, `max_session` = le plus dans une session de la semaine.
4. **Une seule source de règles** : la 30.57 place la construction SQL du comptage dans
   `Shared/Infrastructure/Dbal` avec une période optionnelle ; Wallet l'appelle, sans dépendre de Community et sans
   copier la requête. Si la 30.57 l'a rangée ailleurs, la déplacer dans cette story.
5. **Quête collective** (41.69) : autorisée en mode `total` seulement, comptée sans doublon (une location d'un slot
   joué à plusieurs compte une fois, un item reçu compte une fois). `isCollective()` en tient compte.
6. **Tirage et jeu tiré** (41.67) : un objectif sur compteur ne participe pas au tirage d'un jeu (le compteur a déjà
   son jeu) ; la portée `drawn` ne s'applique pas.
7. **Compteur protégé** : la suppression d'un compteur utilisé par une quête (servie ou non) est refusée (409), comme
   pour un succès. La garde de la 30.57 est étendue.
8. Gates verts ; tests (bornes de la semaine, trois modes, hebdo, option, release exclue, collective dédoublonnée,
   suppression refusée) et jest du formulaire.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1, 6) - `QuestMetric::Counter` (libellé, unités, aide, `scopes()` = `['counter']`),
  `QuestObjective::SCOPE_COUNTER`, validation ; `QuestDraw` l'ignore pour le jeu tiré ; tests domaine.
- [ ] **Task 2** (AC 3, 4, 5) - `DbalWeeklyQuestsQuery` : branche `Counter` qui lit `achievement_counter` en SQL et
  appelle la source partagée avec la période de la semaine ; total collectif ; tests fonctionnels.
- [ ] **Task 3** (AC 2) - Admin des quêtes : choix du compteur, jauge avec le libellé du compteur ; jest.
- [ ] **Task 4** (AC 7) - Garde de suppression : lecture des objectifs de `quest_definition` qui citent le compteur.
- [ ] **Task 5** (AC 8) - `./scripts/gates.sh`, vérification dans l'app.

## Dev Notes

- Wallet lit les tables d'autres contextes en SQL brut (voir l'en-tête de `DbalWeeklyQuestsQuery`) : faire pareil
  pour `achievement_counter`, pas d'import de Community.
- Clé d'objectif (`QuestObjective` « ce que l'objectif compte ») : `counter@counter:{id}` ou équivalent, unique par
  compteur, pour que l'instantané de la 41.67 reste stable.
- Le libellé du compteur peut changer après qu'une quête a été servie : la jauge affiche le libellé courant (choix
  assumé, pas d'instantané du libellé).
- Pas d'em-dash.

### References

- `_bmad-output/implementation-artifacts/30-57-compteurs-de-checks-et-d-items.md`
- `api/src/Wallet/Domain/Enum/QuestMetric.php`, `api/src/Wallet/Domain/ValueObject/QuestObjective.php`
- `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php`
- Stories 41.15, 41.18, 41.64, 41.67, 41.69

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List
