# Story 32.24: Temps de run comptés depuis le premier lancement, jamais négatifs

**Status:** review
**Epic:** 32 - Récap de partie
**Date:** 2026-10-22

## Story

En tant que joueur,
je veux que la durée d'une partie et mon temps pour atteindre l'objectif soient comptés depuis le premier lancement du
serveur,
afin que le récap, le podium et le classement de vitesse affichent des temps justes, jamais négatifs, même quand le
serveur a été relancé en cours de partie ou après la fin.

## Contexte

Jean a signalé des temps négatifs dans le récap (2026-10-22). Toutes les durées partent de `session.started_at` :

- durée de la partie : `finished_at - started_at` (`RunResultsQuery.php:62-66`), sans plancher ;
- temps de chaque joueur : `goal_reached_at - started_at` (`RunResultsQuery.php:112-117`), sans plancher, qui sert
  aussi au tri du podium (`:137-147`) et au gagnant de `EventRecapIndexQuery` ;
- classement de vitesse : `MIN(slot.goal_reached_at) - s.started_at` (`DbalLeaderboardQuery.php:205-268`), qui
  écarte déjà les valeurs `<= 0` (le cas a donc déjà été rencontré, puis masqué).

Or `started_at` ne veut pas dire la même chose selon le chemin de relance :

- **écrasé à chaque `transition(RUNNING)`** (`Session.php:246`) : premier lancement, relance forcée (y compris
  depuis `finished`, `SessionOrchestrator.php:490-526`), redémarrage après crash (`orchestrateRestart`),
  `session.ready` reçu en `idle` (`SessionLifecycleManager::transitionToRunningFromOrchestrateur`, :494-515),
  `reconcilePending` en `launching` ;
- **conservé** par la reprise depuis la sauvegarde (`Session::resumeRunning`, `Session.php:316-331`, commentaire
  « Keep existing startedAt »), chemin normal des stories 17.6 à 17.8.

Un objectif atteint avant une relance qui écrase `started_at` donne un temps joueur négatif ; une relance après la
fin donne `started_at > finished_at`, donc une durée négative. Côté front, `formatDuration`
(`frontend/src/features/recap/recap-format.ts:17-24`) et `formatCompactDuration` (`build-key-figures.ts`) n'ont
aucune garde : -90 s s'affiche « -2 min -30 s ». La page admin (`admin-session-page.tsx:2232-2235`) calcule
`stoppedAt - startedAt` avec un `stoppedAt` conservé à travers les reprises (story 40.3) : clampé à « 0 min ».

Le récap persisté (`session_recap`) ne stocke aucune durée (nodes, edges, local_items, superlatifs en instants
bruts) : tout est recalculé à la lecture, donc rien à reconstruire après correction.

### Décisions (Jean, 2026-10-22)

- **Le récap part du premier lancement**, figé. Les objectifs et la fin sont forcément postérieurs : plus de négatif
  par construction.
- **`started_at` devient partout « dernier lancement »** (uptime, suivi live, activité), y compris sur la reprise
  depuis la sauvegarde, pour qu'il ait enfin un seul sens.
- **Plancher à 0** côté API et à l'affichage, en filet de sécurité pour une donnée incohérente.

## Critères d'acceptation

1. **Nouvelle colonne** `session.first_launched_at` (`datetimetz_immutable`, nullable). Renseignée au premier passage
   en `running`, quel que soit le chemin (`transition(RUNNING)`, `resumeRunning`, `Session::createRunning` des
   entrées hebdo), et **jamais modifiée ensuite** : ni relance, ni reprise, ni relance forcée depuis `finished`, ni
   `forceReset`.
2. **`started_at` = dernier lancement.** `resumeRunning` le met à `$now` comme les autres chemins ; le commentaire
   « Keep existing startedAt » disparaît. `getStartedAt()` est documenté comme tel.
3. **Rattrapage** dans la migration, pour les sessions déjà lancées (`started_at IS NOT NULL`) :
   `first_launched_at = LEAST(started_at, MIN(session_slot.goal_reached_at), MIN(session_feed_event.occurred_at),
   finished_at)` (Postgres `LEAST` ignore les `NULL`). C'est la meilleure borne disponible : le vrai premier
   lancement a pu être écrasé, mais rien de joué ne peut lui être antérieur.
4. **Durée de partie** (`RunResultsQuery`) : `max(0, finished_at - start)` avec `start = first_launched_at ??
   started_at`. Le champ `startedAt` renvoyé par le récap est ce même `start` (c'est le départ du récap).
5. **Temps joueur** (`RunResultsQuery`) : `max(0, goal_reached_at - start)`, même `start`. Podium, « Objectif
   atteint en », tableau des joueurs, carte de partage et gagnant de `EventRecapIndexQuery` en héritent.
6. **Classement de vitesse** (`DbalLeaderboardQuery::computeSpeedPage`, requêtes événement et parties perso) :
   `COALESCE(s.first_launched_at, s.started_at)` au lieu de `s.started_at`. Le filtre `<= 0` reste.
7. **Page résultats d'événement** (`evenements/[eventSlug]/resultats/page.tsx:70-77`) : la durée part du premier
   lancement. `SessionView` expose `firstLaunchedAt` ; `SessionResultsQuery` renvoie le premier lancement dans son
   champ `startedAt` (comme le récap), donc la page ne change pas ; la garde existante reste.
8. **Statistiques** (`DbalSessionStatsQuery.php:38-45`) : les tendances « sessions lancées » comptent
   `first_launched_at` (une reprise ne déplace plus une partie dans une autre période).
9. **Affichage** : `formatDuration` et `formatCompactDuration` traitent une valeur négative comme 0 (jamais de
   signe moins affiché).
10. **Admin** (`admin-session-page.tsx`) : affiche « Premier lancement » et « Dernier lancement ». La durée de
    fonctionnement part du dernier lancement jusqu'à maintenant si la session tourne, jusqu'à `stoppedAt` sinon,
    et n'utilise un `stoppedAt` que s'il est postérieur au dernier lancement (sinon : maintenant pour une session
    active, rien d'affiché pour une session arrêtée).
11. **Hors périmètre** : `WeeklyEntry.launchedAt` et `RecordWeeklyGoal` (déjà figés au premier lancement et
    clampés), les labels d'inactivité (`personal-run-card.tsx`, `formatInactivity`), l'uptime du conteneur Docker.
12. **Tests** :
    - unitaires `Session` : `first_launched_at` posé au premier `running` par chacun des chemins, inchangé après
      relance forcée depuis `finished`, crash, reprise et `forceReset` ; `started_at` avancé par `resumeRunning` ;
    - `RunResultsQuery` : objectif atteint avant une relance (durée et temps positifs, comptés depuis le premier
      lancement) ; `finished_at` antérieur au dernier lancement ; données incohérentes ramenées à 0 ;
    - fonctionnel classement de vitesse avec une session relancée : le joueur garde son temps réel ;
    - migration : rattrapage vérifié sur une session dont `started_at` est postérieur à un objectif ;
    - jest : `formatDuration(-90)` et `formatCompactDuration(-90)` ; durée admin d'une session reprise.
13. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] Domaine `Session` : colonne et getter `firstLaunchedAt`, pose au premier `running` (AC 1), `resumeRunning`
      avance `startedAt` (AC 2), `toView()` / `SessionView` exposent `firstLaunchedAt` (AC 7)
- [x] Migration `Version20261022100000` : `ADD first_launched_at` + rattrapage (AC 3) ; `down` supprime la colonne
- [x] `RunResultsQuery` : départ et planchers (AC 4, 5)
- [x] `DbalLeaderboardQuery` (AC 6), `DbalSessionStatsQuery` (AC 8), `SessionResultsQuery` (AC 7)
- [x] Front : `recap-format.ts`, `build-key-figures.ts` (AC 9), page résultats (AC 7), page admin et
      `admin-sessions-api.ts` (AC 10)
- [x] Tests (AC 12), gates (AC 13)

## Dev Notes

- `Session` est construit par un constructeur à arguments nommés (`Session.php:85-130`) : ajouter
  `firstLaunchedAt` en argument optionnel `= null` en fin de liste pour ne pas casser les appels existants
  (`createRunning`, fixtures, tests).
- La pose « au premier passage » s'écrit `$this->firstLaunchedAt ??= $now;` dans le bloc `RUNNING` de `transition`
  et dans `resumeRunning`, et `firstLaunchedAt: $now` dans `createRunning`. `forceReset` n'y touche pas.
- Pas de logique dans le domaine au-delà de ça : les planchers vivent dans les queries (Application) et le front.
- Lire les colonnes DBAL comme le reste de `DbalLeaderboardQuery` (`is_string(...)`), le `COALESCE` se met dans le
  `select` et le `groupBy`.
- Rattrapage SQL de la migration :
  ```sql
  UPDATE session s SET first_launched_at = LEAST(
      s.started_at,
      (SELECT MIN(sl.goal_reached_at) FROM session_slot sl WHERE sl.session_id = s.id),
      (SELECT MIN(f.occurred_at) FROM session_feed_event f WHERE f.session_id = s.id),
      s.finished_at)
  WHERE s.started_at IS NOT NULL
  ```
  Vérifier les noms de colonnes de `session_slot` (`goal_reached_at`, `session_id`) dans les migrations avant
  d'écrire (cf. mémoire « Doctrine diff - faux positifs destructeurs » : ne pas reprendre un `migrations:diff`
  aveuglément).
- Le contrat front du récap (`recap-api.ts`, gardes de type `:138`, `:161`) ne change pas de forme : `startedAt`
  garde son nom, seule sa valeur devient le premier lancement.

### Mise en prod

- Migration `Version20261022100000` (jouée par `api-migrations`), qui rattrape les sessions existantes. Rien à
  reconstruire : les durées du récap sont recalculées à la lecture.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5 (1M context)

### Completion Notes List

- `first_launched_at` posé par `$this->firstLaunchedAt ??= $now` dans le bloc `RUNNING` de `transition` et dans
  `resumeRunning`, `firstLaunchedAt: $now` dans `createRunning` ; `forceReset` n'y touche pas. `resumeRunning` avance
  désormais `startedAt` (dernier lancement sur tous les chemins).
- `RunResultsQuery` : `runStart()` (`firstLaunchedAt ?? startedAt`) et `elapsed()` (plancher à 0) pour la durée et
  les temps joueurs ; le champ `startedAt` du récap est ce départ.
- Classement de vitesse : constante `RUN_START = COALESCE(s.first_launched_at, s.started_at)` dans le `select` et le
  `groupBy` des deux requêtes. Stats : `runsLaunched` et `eventSessionsLaunched` sur `first_launched_at`.
- Écart à l'AC 7 : pas de nouveau champ côté page résultats, `SessionResultsQuery` met le premier lancement dans
  `startedAt` ; le contrat du front ne bouge pas.
- Admin : logique extraite dans `session-uptime.ts` (`sessionUptimeMs`, testée) ; une session active compte jusqu'à
  maintenant, une session terminée ou arrêtée jusqu'à `finishedAt` / `stoppedAt` seulement s'ils suivent le dernier
  lancement, sinon rien n'est affiché. L'en-tête affiche « Premier lancement », et « Dernier lancement » quand une
  relance l'a déplacé.
- Rattrapage vérifié sur la base de dev dans une transaction annulée : 56 sessions remplies, objectifs antérieurs au
  départ 5 -> 0, aucune durée négative.
- `AdminSessionStatsTest` : son helper pose `started_at` en SQL ; il pose maintenant aussi `first_launched_at`, comme
  un vrai lancement.

### File List

- `api/migrations/Version20261022100000.php` (nouveau)
- `api/src/Sessions/Domain/Entity/Session.php`, `api/src/Sessions/Domain/ValueObject/SessionView.php`
- `api/src/Sessions/Application/Query/RunResultsQuery.php`, `api/src/Sessions/Application/Query/SessionResultsQuery.php`
- `api/src/Sessions/Infrastructure/Dbal/DbalLeaderboardQuery.php`, `api/src/Sessions/Infrastructure/Dbal/DbalSessionStatsQuery.php`
- `api/tests/Unit/Sessions/SessionFirstLaunchTest.php` (nouveau), `api/tests/Functional/RunResultsTest.php`,
  `api/tests/Functional/CommunityLeaderboardTest.php`, `api/tests/Functional/AdminSessionStatsTest.php`
- `frontend/src/features/recap/recap-format.ts` (+ test), `frontend/src/features/recap/build-key-figures.ts` (+ test)
- `frontend/src/features/admin/session-uptime.ts` (nouveau, + test), `frontend/src/features/admin/admin-session-page.tsx`,
  `frontend/src/features/admin/admin-sessions-api.ts`
