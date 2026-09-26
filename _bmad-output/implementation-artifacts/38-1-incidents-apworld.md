# Story 38.1: Incidents apworld

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-24
**Dépend de :** rien. Fondation des stories 38.2, 38.3, 38.4, 38.6 et 38.9.

## Story

En tant qu'admin d'ArchiLAN,
je veux qu'un apworld qui échoue à sa génération par défaut ouvre un **incident** qui reste ouvert
jusqu'à ce que le problème soit réglé,
afin qu'un apworld cassé ne reste plus des semaines en production signalé par un simple badge que
personne ne regarde.

## Contexte

L'apworld Crystal Project v0.17.0 a échoué à son test de génération (story 9.38) sur 100 % des seeds
depuis son import du 2026-07-16. Le verdict existait, mais seulement comme un badge sur la page admin du
jeu : l'orchestrateur le calcule, l'API le lit à l'affichage (`RunnerGateway::fetchApworldPreflights()`)
et ne le garde nulle part. Il n'y a donc **ni mémoire, ni suivi, ni déclencheur** possible pour une alerte.

Cette story crée l'objet qui manque : l'**incident**. Elle ne notifie personne (38.2) et n'a pas encore
d'écran dédié (38.3) : elle pose le modèle, ses règles, et la réconciliation qui l'alimente.

### Pourquoi une réconciliation plutôt qu'un webhook

Les webhooks de l'orchestrateur sont fire-and-forget, sans retry (`orchestrateur/internal/webhook/sender.go`).
Un événement perdu serait un incident jamais ouvert, c'est-à-dire exactement la panne qu'on cherche à
supprimer. Une tâche planifiée qui relit tous les verdicts et en déduit l'état des incidents est
**idempotente** : si un passage rate, le suivant rattrape. Elle ne touche qu'un dépôt et ne coûte qu'un
appel `GET /apworlds` toutes les cinq minutes.

## Critères d'acceptation métier

1. **Ouverture.** Quand le jeu sert un apworld dont le test de génération est en échec, un incident de
   type « test de génération en échec » est ouvert pour ce jeu et ce hash, avec l'extrait d'erreur.
2. **Pas de doublon.** Tant que cet incident est actif (ouvert ou pris en charge), un nouvel échec du même
   hash ne crée pas de second incident : il met à jour l'erreur, la date de dernier constat et le nombre
   d'occurrences.
3. **Résolution automatique.** Un incident actif est résolu automatiquement, avec la mention « résolu
   automatiquement », quand :
   - le test du même hash repasse au vert ;
   - ou le jeu ne sert plus ce hash (nouvel apworld importé, apworld retiré).
4. **Prise en charge.** Un admin peut prendre un incident en charge : l'incident garde **qui** et **depuis
   quand**. Un incident pris en charge reste actif et continue d'être résolu automatiquement.
5. **Résolution manuelle et ignorer.** Un admin peut résoudre ou ignorer un incident actif. Un incident
   ignoré n'est **pas rouvert** tant que le jeu sert le même hash : ignorer vaut pour ce hash-là.
6. **Rechute.** Si un hash dont l'incident a été résolu échoue de nouveau, un **nouvel** incident est
   ouvert : l'historique de l'ancien est conservé.
7. **Forçage admin.** Un verdict en échec que l'admin a forcé (« autoriser quand même », story 9.38 AC4)
   n'ouvre pas d'incident : l'admin a déjà vu et tranché. Un incident actif sur ce hash est ignoré au
   nom du forçage.
8. **Runner indisponible.** Si l'orchestrateur ne répond pas, **rien ne change** : aucun incident n'est
   ouvert, aucun n'est résolu. Un runner en panne ne doit jamais « guérir » un apworld cassé.
9. **Verdict absent ou en cours.** Un verdict `pending`, `skipped` ou absent ne change rien aux incidents.

## Critères d'acceptation techniques

10. **Agrégat `ApworldIncident`** dans `GameSelection/Domain/Entity/`, mappé sur une nouvelle table
    `apworld_incident`. Champs : `id`, `gameId`, `apworldHash`, `type`, `status`, `error`, `openedAt`,
    `lastSeenAt`, `occurrences`, `acknowledgedBy`, `acknowledgedAt`, `closedAt`, `closedBy`. Pas de
    colonne `resolution` : une clôture sans `closedBy` est automatique (`wasClosedAutomatically()`).
11. **Enums de domaine** dans `GameSelection/Domain/Enum/` : `ApworldIncidentType` (pour cette story :
    `preflight_failed`) et `ApworldIncidentStatus` (`open`, `acknowledged`, `resolved`, `ignored`).
    `isActive()` vaut vrai pour `open` et `acknowledged`.
12. **Méthodes métier pures** (AC-D3 : ni horloge, ni hasard, ni identifiant généré dans le domaine) :
    `open(id, gameId, hash, type, error, now)`, `recordRecurrence(error, now)`,
    `acknowledge(userId, now)`, `resolve(now, ?userId)`, `ignore(now, ?userId)`. Une transition
    interdite (résoudre un incident déjà clos, prendre en charge un incident ignoré) lève une exception
    de domaine `ApworldIncidentTransitionException`.
13. **Dédoublonnage en base** : index unique partiel sur `(game_id, apworld_hash, type)` pour
    `status IN ('open', 'acknowledged')`, déclaré dans le mapping (`options: ['where' => ...]`, comme
    `Membership`) et dans la migration. Le code ne compte pas seulement sur la lecture préalable :
    deux réconciliations concurrentes ne peuvent pas ouvrir deux incidents.
14. **Port de dépôt** `ApworldIncidentRepositoryInterface` (`Domain/Repository/`) : `save`,
    `findActive(gameId, hash, type)`, `findLatestClosed(gameId, hash, type)`, `findAllActive()`,
    `flush`. Implémentation `DoctrineApworldIncidentRepository` (`Infrastructure/Doctrine/`).
15. **Commande `ReconcileApworldIncidents`** (`Application/Command/`), qui renvoie un record
    `ReconcileApworldIncidentsResult` (runner joignable ou non, ids des incidents ouverts, résolus et
    ignorés) : jamais un
    `array` brut (AC-A3, `validateCommandArrayReturns`). Les ids ouverts servent à 38.2.
16. **Règle d'ouverture isolée** dans une commande `RecordApworldIncident` (`Application/Command/`)
    appelée par la réconciliation : « ouvrir, ou compter une récurrence, sauf si ignoré pour ce hash ».
    38.4 et 38.6 la réutiliseront pour leurs propres types d'incident, sans dupliquer la règle.
17. **Planification** : `ReconcileApworldIncidentsMessage` toutes les 5 minutes dans `api/src/Schedule.php`,
    handler dans `Application/Handler/`. La commande console `app:apworlds:incidents-reconcile`
    (préfixe `app:apworlds:` du contexte) permet de la lancer à la main.
18. **Transaction unique** : une réconciliation fait un seul `flush()` à la fin (AC-A4).
19. `composer gates` passe.

## Ordre TDD

Chaque test est écrit **avant** le code qu'il couvre, lancé rouge, puis passé au vert.

1. `tests/Unit/GameSelection/ApworldIncidentTest.php` - l'agrégat, sans base :
   - `testOpenStartsAnActiveIncidentWithOneOccurrence`
   - `testRecordRecurrenceUpdatesErrorLastSeenAndCount`
   - `testAcknowledgeRecordsWhoAndWhenAndStaysActive`
   - `testResolveClosesAndRecordsAutomaticResolutionWithoutUser`
   - `testResolveByAdminRecordsManualResolution`
   - `testIgnoreClosesAsIgnored`
   - `testResolvingAClosedIncidentThrows`
   - `testAcknowledgingAnIgnoredIncidentThrows`
2. `tests/Unit/GameSelection/RecordApworldIncidentTest.php` - avec un dépôt en mémoire :
   - `testOpensWhenNoActiveIncident`
   - `testCountsARecurrenceOnTheActiveIncident`
   - `testDoesNotReopenAnIgnoredIncidentForTheSameHash`
   - `testOpensANewIncidentAfterAResolvedOne`
3. `tests/Unit/GameSelection/ReconcileApworldIncidentsTest.php` - verdicts et jeux simulés :
   - `testFailedVerdictOnServedHashOpensAnIncident`
   - `testPassedVerdictResolvesTheActiveIncident`
   - `testIncidentOnAHashNoLongerServedIsResolved`
   - `testOverriddenVerdictOpensNothingAndIgnoresTheActiveIncident`
   - `testRunnerUnavailableChangesNothing`
   - `testPendingSkippedOrMissingVerdictChangesNothing`
   - `testReturnsTheIdsOfOpenedIncidents`
4. `tests/Functional/ApworldIncidentPersistenceTest.php` - la vraie base (les tests fonctionnels sont à
   plat dans `tests/Functional/`) :
   - `testFindActiveIgnoresClosedIncidents`
   - `testUniqueIndexRejectsASecondActiveIncidentForTheSameKey`
   - `testFindLatestClosedReturnsTheMostRecent`
   - `testServedApworldsListsOnlyGamesWithAnApworld`

## Tasks / Subtasks

- [x] **Task 1** (AC 10-12) - Enums, exception et agrégat, pilotés par les tests unitaires de l'étape 1.
- [x] **Task 2** (AC 14, 16) - Port de dépôt, double en mémoire pour les tests, `RecordApworldIncident`.
- [x] **Task 3** (AC 1-9, 15, 18) - `ReconcileApworldIncidents` et son record de résultat.
- [x] **Task 4** (AC 13, 14) - Mapping Doctrine, migration (table + index unique partiel), dépôt Doctrine,
  test fonctionnel.
- [x] **Task 5** (AC 17) - Message, handler, entrée dans `Schedule.php`, commande console.
- [x] **Task 6** (AC 19) - `composer gates`.

## Dev Notes

- **Placement DDD.** Tout vit dans `GameSelection`. Le verdict vient de
  `App\Sessions\Application\Port\RunnerGatewayInterface::fetchApworldPreflights()`, déjà consommé par
  `AdminGameLibrary` : pas de nouveau port vers l'orchestrateur. Sa forme :
  `array<hash, array{status, error, checkedAt, overridden, blocks}>`, et **`[]` quand le runner est
  indisponible**. C'est ce `[]` qui porte l'AC 8 : il faut le distinguer d'un catalogue vide.
- **Jeux servis.** Seuls comptent les jeux qui ont un `apworldHash`. Retenu : une requête DBAL dédiée,
  `ServedApworldsQueryInterface` (`Application/Query/`), qui ne lit que `id` et `apworld_hash`, plutôt
  que d'hydrater tout le catalogue toutes les cinq minutes.
- **Identifiants.** L'id est fabriqué dans l'Application (`bin2hex(random_bytes(16))`) et passé à
  `ApworldIncident::open()`. Le domaine ne tire rien au hasard, contrairement à
  `Notification::create()` qui le fait encore.
- **Horloge.** `Psr\Clock\ClockInterface` injecté dans les commandes, jamais `new \DateTimeImmutable()`.
- **Index unique partiel.** Déclaré sur l'entité avec `options: ['where' => ...]`, écrit sous la forme
  que PostgreSQL normalise (précédent : `Membership`). Deux raisons : le schéma de test est construit
  depuis le mapping (`BuildSchemaOnceSubscriber`), pas depuis les migrations, donc un index créé
  seulement en migration n'existerait pas en test ; et `doctrine:migrations:diff` ne propose pas de le
  supprimer.
- **Erreur stockée.** Le verdict orchestrateur est déjà tronqué à 2000 caractères
  (`preflightErrorExcerptMax`). Le stocker tel quel ; `GenerationFailureParser::summarize()` servira à
  l'affichage (38.3), pas au stockage.
- **Hors périmètre.** Aucune notification (38.2), aucun endpoint ni écran (38.3). Les méthodes
  `acknowledge`, `resolve` et `ignore` existent dès maintenant parce que ce sont des règles de domaine,
  mais rien ne les expose encore.

### Project Structure Notes

- `api/src/GameSelection/Domain/Entity/ApworldIncident.php`
- `api/src/GameSelection/Domain/Enum/ApworldIncidentType.php`, `ApworldIncidentStatus.php`
- `api/src/GameSelection/Domain/Exception/ApworldIncidentTransitionException.php`
- `api/src/GameSelection/Domain/Repository/ApworldIncidentRepositoryInterface.php`
- `api/src/GameSelection/Application/Command/RecordApworldIncident.php`
- `api/src/GameSelection/Application/Command/ReconcileApworldIncidents.php` + `ReconcileApworldIncidentsResult.php`
- `api/src/GameSelection/Application/Message/ReconcileApworldIncidentsMessage.php`
- `api/src/GameSelection/Application/Handler/ReconcileApworldIncidentsHandler.php`
- `api/src/GameSelection/Infrastructure/Doctrine/DoctrineApworldIncidentRepository.php`
- `api/src/GameSelection/Application/Query/ServedApworldsQueryInterface.php` + `ServedApworld.php`
- `api/src/GameSelection/Infrastructure/Dbal/DbalServedApworldsQuery.php`
- `api/src/GameSelection/Presentation/Command/ReconcileApworldIncidentsCommand.php`
- `api/migrations/Version20260924120000.php`

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-38-sante-et-mise-a-jour-automatique-des-apworlds.md]
- [Source: _bmad-output/implementation-artifacts/9-38-apworld-upload-preflight-generation.md] - le verdict et son forçage
- [Source: api/src/GameSelection/Application/Service/AdminGameLibrary.php] - `preflightForGame()`, lecture actuelle du verdict
- [Source: orchestrateur/internal/service/apworld_preflight.go] - statuts `pending`, `passed`, `failed`, `skipped`

## Dev Agent Record

### Déroulé TDD

Chaque étape a été menée rouge, puis vert, avec un rouge **comportemental** (squelette aux bonnes
signatures) avant d'implémenter, pour ne pas confondre « la classe n'existe pas » avec « la règle est
fausse ».

| Étape | Rouge | Vert |
|---|---|---|
| Agrégat `ApworldIncident` | 11 échecs sur 12 (seul `open()` passait) | 12 tests |
| `RecordApworldIncident` | 6 échecs sur 7 | 7 tests |
| `ReconcileApworldIncidents` | 9 échecs sur 11 | 11 tests |
| Persistance (fonctionnel) | service absent | 6 tests, index partiel compris |

Le rouge de la réconciliation a révélé deux tests qui passaient **à vide** contre le squelette (ils
comparaient deux listes vides). Ils vérifient désormais qu'un incident a bien été ouvert avant de
contrôler sa résolution.

### Vérifications

- `composer gates` : vert, 1983 tests.
- Migration rejouée sur une base vierge, puis `doctrine:schema:update --dump-sql` : aucune différence
  sur `apworld_incident`. PostgreSQL normalise le `WHERE` de l'index exactement comme le mapping.
- **Scénario de bout en bout**, sur une copie de la base locale et l'orchestrateur local :
  1. Crystal Project pointé sur l'apworld v0.17.0 (verdict `failed`) : `app:apworlds:incidents-reconcile`
     ouvre l'incident ; un second passage compte une récurrence sans rouvrir.
  2. Jeu repassé sur la v0.18.2 (verdict `passed`) : l'incident est résolu automatiquement.
  3. **Trouvaille** : le même passage a ouvert un incident sur **Beat Saber**, dont l'apworld échoue à
     son test dans l'orchestrateur local. Un apworld cassé, silencieux, repéré sans qu'on le cherche.

### Écarts à la rédaction initiale

- Pas de colonne `resolution` : dérivée de `closedBy` (AC 10 mis à jour).
- L'index partiel est dans le mapping **et** la migration (AC 13 et Dev Notes mis à jour).
- Commande console nommée `app:apworlds:incidents-reconcile`, selon le préfixe du contexte.
- Nouvelle méthode `ApworldIncidentType::followsServedApworld()` : dit si un type d'incident se clôt
  quand le jeu change d'apworld. Vraie pour `preflight_failed` ; 38.6 ajoutera des types clés sur un
  hash candidat, pour lesquels elle sera fausse.
- Faux de test en mémoire `InMemoryApworldIncidentRepository` (dans `tests/`), plutôt que des stubs :
  les règles portent sur le contenu du dépôt.

## Corrections de revue (2026-09-26)

- **Un verdict relu n'est pas un nouvel échec.** L'incident garde sa dernière observation (le `checkedAt` du
  verdict, colonne `last_observation`). Relire le même verdict à chaque passe ne compte plus de récurrence
  (il y en avait environ 288 par jour), et un incident résolu à la main sur ce verdict ne se rouvre plus :
  seul un nouveau test en échec le rouvre. Nouvelle issue `AlreadySeen` dans `RecordApworldIncident`.
- **Une passe à la fois** : `ExclusivePassLockInterface`, implémenté par un advisory lock PostgreSQL
  (`PostgresAdvisoryPassLock`). Une seconde passe (console, ou passe lente) s'arrête et le dit, au lieu de
  planter sur l'index unique.
- **Plus de N+1** : les incidents actifs sont chargés une fois par passe. `reconcile()` accepte les verdicts
  déjà lus, pour la lecture unique de la passe (voir 38.6).
