# Story 43.15: Duel hebdo entre amis

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.8 (bloc « Tes amis cette semaine »)

## Story

En tant que joueur,
je veux défier un ou plusieurs amis sur l'hebdo de la semaine,
afin d'avoir une raison de jouer cette semaine et de comparer nos résultats.

## Contexte

Inspiré des *Friend Quests* Duolingo et des défis de clubs Strava. Les hebdos sont des courses solo :
`WeeklyRunEntry` (`completionTimeSeconds`, `goalReachedAt`), inscription `OptInToWeeklyRun`, objectif
`RecordWeeklyGoal`, fin de semaine `StopWeeklyRunsMessageHandler`. Le fil d'activité ne connaît que deux types
(`ActivityEntry::TYPE_RUN_FINISHED`, `TYPE_FRIENDSHIP`).

## Critères d'acceptation

1. Depuis la page de l'hebdo en cours, défier un ou plusieurs amis acceptés (5 max) ; chacun reçoit une
   notification `weekly_duel` et accepte ou refuse. Accepter ne l'inscrit pas à l'hebdo : la page le propose.
2. Le duel accepté affiche un mini-classement des participants (statut, temps) sur la page de l'hebdo et dans
   `/compte` jusqu'à la fin de la semaine ; gagne le meilleur temps à objectif atteint ; personne à l'objectif =
   pas de gagnant.
3. À la fin de l'hebdo (`StopWeeklyRunsMessageHandler`, après commit), chaque participant reçoit le résultat
   (« Tu bats *X* de 12 min ») et une entrée d'activité `weekly_duel` est publiée (audience : amis).
4. Un blocage annule le duel pour la paire concernée.
5. Plafond : 3 duels créés par joueur et par semaine.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Domaine/Migration** : `WeeklyDuel` (hebdo, créateur, participants et réponses, résultat).
- [x] **Application** : création, réponse, résolution branchée sur la fin de l'hebdo.
- [x] **Community** : nouveau type `ActivityEntry` `weekly_duel` et son rendu dans le fil.
- [x] **Front** : bouton « Défier », mini-classement, résultat, `messageFor` / `hrefFor`.
- [x] Tests (gagnant, égalité, aucun objectif, refus, blocage, plafond) et gates.

## Notes

- Variante coopérative écartée de cette story : « objectif commun de *N* checks à deux sur la semaine ».
  Agréger les checks de plusieurs parties est coûteux ; à reprendre en story séparée si les duels prennent.

## Dev Notes

- **Domaine** (WeeklyRuns) : `WeeklyDuel` (`weekly_duel` : hebdo, créateur, `resolved_at`, `winner_id` ;
  `MAX_OPPONENTS` = 5, `MAX_PER_WEEK` = 3) et `WeeklyDuelParticipant` (`weekly_duel_participant` : statut
  pending / accepted / declined / cancelled, unique (duel, membre)). Le créateur a sa ligne, acceptée d'office.
  Migration `Version20261010150000`.
- **Classement** : le calcul de la 43.8 (meilleure tentative, objectif avant lancée avant inscrit, temps) est
  extrait dans `Application/Support/WeeklyStanding`, réutilisé par `WeeklyRunFriendsQuery` et les duels. Un membre
  qui a accepté sans s'inscrire apparaît « Pas inscrit » ; un défié sans réponse, « Pas encore répondu ».
- **Application** :
  - `WeeklyDuelService` (facade) : `challenge` (amis acceptés seulement via `FriendCircleQuery`, les autres sont
    écartés ; 5 max ; hebdo active), `accept` / `decline`, `forViewer` (duels non résolus d'hebdos actives).
    Notification `weekly_duel` aux défiés après l'enregistrement.
  - `ResolveWeeklyDuels::forRun`, appelé par `StopWeeklyRunsMessageHandler` après le `flush` de l'hebdo terminée
    (erreur journalisée, ne bloque pas l'arrêt). Gagnant = premier du classement s'il a atteint l'objectif, sinon
    aucun. Après l'enregistrement : `weekly_duel_result` à chaque membre ayant accepté (`outcome` won / lost / none,
    `opponentName`, `marginSeconds`), et une entrée d'activité `weekly_duel` (acteur = gagnant, `withUserId` =
    deuxième) visible des amis du gagnant. Un duel que personne n'a accepté se ferme sans bruit ; sans gagnant,
    pas d'entrée d'activité.
- **Plafond hebdomadaire** : compté sur les duels créés depuis le début de l'hebdo concernée (`started_at`),
  toutes hebdos de la semaine confondues.
- **Blocage** (`WeeklyDuelBlockRule`) : appliqué à la lecture, à la réponse et à la résolution (pas de hook dans
  `FriendshipService::block`, pour ne pas faire dépendre Community de WeeklyRuns). Si le créateur est concerné, l'autre
  quitte le duel ; sinon le membre bloqué le quitte, le bloqueur garde sa place.
- **Routes** : `GET /api/v1/weekly-duels[?weeklyRun=]`, `POST /api/v1/weekly-runs/{id}/duels` `{userIds}`
  (201 ; 422 aucun ami / trop d'amis ; 429 plafond ; 409 hebdo finie), `POST /api/v1/weekly-duels/{id}/accept|decline`.
- **Front** : `weekly-duels-api.ts`, `weekly-duels.tsx` (`WeeklyDuels`, `ChallengeFriendsButton`) sur la page de
  l'hebdo (sous « Tes amis cette semaine », hebdo active) et sur `/compte` ; cloche (`weekly_duel`,
  `weekly_duel_result`, lien `/runs-hebdo`) ; fil d'activité (« a gagné un duel hebdo sur X contre Y de 12 min »).
  `formatMargin` et `weeklyDuelResultTitle` dans `notification-content.ts`.
- **Tests** : `WeeklyDuelTest` (5 : acceptation + classement, gagnant, aucun objectif, blocage, plafonds),
  `weekly-duels.test.tsx` (5) ; `StopWeeklyRunsMessageHandlerTest` reçoit le résolveur.
- **A déployer** : la migration `Version20261010150000`.
