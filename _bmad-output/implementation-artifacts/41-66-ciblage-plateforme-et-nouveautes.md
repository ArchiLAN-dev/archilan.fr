# Story 41.66: Objectifs ciblés sur des plateformes ou les nouveautés du catalogue

**Status:** ready-for-dev
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-01

## Story

En tant qu'admin,
je veux viser des plateformes ou les jeux récemment mis en ligne,
afin d'écrire des semaines à thème (« Semaine Nintendo ») et de faire jouer les nouveaux apworlds.

## Contexte

Suite de la 41.64. Aujourd'hui un objectif ne vise qu'un jeu ou un événement (41.18). Le catalogue n'a ni genre
ni tag ; la seule classification est la famille de plateforme, résolue par
`PlatformCategory::resolve(override admin, plateformes IGDB)` : le champ `game.platform_families` n'est qu'un
override, nul pour la plupart des jeux. Les familles sont par console (« Super Nintendo », « Nintendo 64 »,
« Switch »...), et le vocabulaire est ouvert (un nom IGDB inconnu reste tel quel).

## Critères d'acceptation

1. **Cible plateformes** : un objectif ciblable peut viser **une ou plusieurs familles** (`scope = platform`) : il
   ne compte que les sessions des jeux dont au moins une famille résolue est dans la liste. « Semaine Nintendo » =
   cocher les familles Nintendo. Un jeu sans famille ne compte jamais.
2. **Cible nouveautés** : un objectif ciblable peut viser les nouveautés (`scope = recent`, 7 à 90 jours) : il ne
   compte que les jeux **mis en ligne** depuis moins de ce nombre de jours au **début de la semaine servie** (la
   cible ne bouge pas en cours de semaine). Mis en ligne = date de la première promotion d'un candidat apworld
   (`apworld_candidate`, story 38.6), à défaut `game.created_at` pour les jeux d'avant l'historique.
3. **Hebdos** : comme toute cible (41.18), les hebdos ne comptent pas ; l'objectif l'affiche (« hors hebdos »).
4. **Affichage** : « 3 goals sur un jeu Super Nintendo ou Nintendo 64 » (au-delà de 3 familles : « sur un jeu de
   la sélection », avec la liste au survol), « 1 partie sur un jeu mis en ligne ces 30 derniers jours ».
5. **Validation** : une famille absente du catalogue, une liste vide ou un nombre de jours hors bornes est refusé.
   Les familles proposées sont celles réellement résolues sur les jeux du catalogue.
6. Gates verts ; tests (jeu sans famille, famille venue d'IGDB, override admin, jeu multi-familles, jeu d'avant
   l'historique des candidats, bornes de la fenêtre) et des écrans.

## Notes techniques

- La résolution des familles et de la date de mise en ligne vit dans GameSelection : le Wallet la lit par un port
  de son Application (`QuestGameCatalog` : familles par jeu, date de mise en ligne par jeu), implémenté en
  infrastructure. Le filtre se fait en PHP sur la liste des jeux, puis en SQL par `slot.game_id IN (:ids)`.
- `QuestObjective` : `SCOPE_PLATFORM` (scopeId = liste JSON de familles) et `SCOPE_RECENT` (scopeId = jours) ;
  `ManageQuests::objectives` et `DbalWeeklyQuestsQuery::scope()` les apprennent.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1, 2, 5) - Port `QuestGameCatalog`, domaine et SQL des deux cibles ; tests.
- [ ] **Task 2** (AC 3, 4) - Noms des cibles côté membre, admin et Discord ; formulaire ; tests jest.
- [ ] **Task 3** (AC 6) - Gates, vérification dans l'app.
