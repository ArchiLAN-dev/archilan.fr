# Story 42.2: Section Parties des statistiques admin

**Status:** review
**Epic:** 42 - Statistiques admin
**Date:** 2026-10-03

## Story

En tant qu'admin,
je veux voir combien de parties se lancent et se terminent, et quels jeux sont joués, sur la période choisie,
afin de savoir si l'offre de parties (runs privées, hebdos, événements) vit et ce qui attire les joueurs.

## Contexte

Deuxième section de la page `/admin/statistiques` (story 42.1), sur le même socle (`StatsPeriod`, chiffres clés
avec écart, `TrendChart`). Trois sortes de parties coexistent :

- **runs privées** : une ligne `run`, chaque lancement ou relance crée une `session` dont `event_id` porte l'id
  de la run (`LaunchPersonalRunJobHandler`) ;
- **sessions d'événement** : une `session` dont `event_id` est un événement ;
- **hebdos** : une `weekly_entries` par tentative, lancée (`launched_at`) puis éventuellement terminée
  (`goal_reached_at`), sans ligne `session`.

## Critères d'acceptation

1. Endpoint `GET /api/v1/admin/stats/sessions?period=` (admin seulement, période comme en 42.1) qui renvoie, par
   tranche et en total avec la période précédente :
   - **runs créées** (`run.created_at`) ;
   - **runs lancées** : runs distinctes dont une session a démarré dans la tranche (une relance ne compte pas
     deux fois ; le total de la période est un distinct) ;
   - **sessions d'événement lancées** (sessions démarrées qui ne sont pas celles d'une run) ;
   - **hebdos lancées** (`weekly_entries.launched_at`) et **hebdos terminées** (`goal_reached_at`) ;
   - **goals atteints** dans les sessions (slots dont `goal_reached_at` tombe dans la tranche).
2. Deux chiffres du moment : sessions en cours (statut `running`) et runs actives.
3. **Jeux les plus joués** sur la période : les 10 jeux qui comptent le plus de joueurs distincts ayant fait un
   check (fil de session, même lien que les membres actifs de la 42.1, co-joueurs compris), avec le nombre de
   checks ; nom du jeu, jamais de nom de joueur. Les hebdos n'y figurent pas (pas de fil persisté).
4. Section « Parties » de la page, entre Communauté et Pelles : chiffres clés avec écart, un graphe en barres
   des lancements (runs, sessions d'événement, hebdos), un graphe en ligne des goals atteints (sessions et hebdos),
   le tableau des jeux les plus joués ; chaque graphe porte sa définition. Section chargée indépendamment.
5. Gates : `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - Requête Sessions (interface Application, DBAL en Infrastructure), endpoint ; tests
  fonctionnels (relance comptée une fois, session d'événement vs run, hebdos, top des jeux, admin seulement).
- [x] **Task 2** (AC 4) - Front : section, graphes, tableau ; tests.
- [x] **Task 3** (AC 5) - Gates.

## Notes techniques

- La requête vit dans Sessions et lit `run` et `weekly_entries` en SQL, comme la section Communauté lit les
  tables des autres contextes.
- Démarre sur la branche de la 42.1 (socle commun) ; sa PR vise `develop` une fois la #696 mergée.

## Dev Agent Record

- `SessionStatsQueryInterface` / `DbalSessionStatsQuery` (Sessions), `GET /api/v1/admin/stats/sessions`.
- Extraits partagés : `DbalStatsReader` (tranches, totaux, distinct) et `DbalSlotCheckSource` (checks du fil reliés aux
  joueurs du slot), utilisés par Communauté et Parties ; la section Communauté est passée dessus sans changer ses
  résultats.
- Front : section Parties entre Communauté et Pelles (chiffres clés, lancements, goals, jeux les plus joués).
