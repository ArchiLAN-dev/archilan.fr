# Story 38.4: Incidents issus des vraies générations

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-24
**Dépend de :** 38.1 (`RecordApworldIncident`).

## Story

En tant qu'admin d'ArchiLAN,
je veux qu'un apworld qui fait échouer la génération d'un joueur **avec son YAML par défaut** ouvre un
incident, même si le test d'import était passé,
afin de ne pas dépendre d'un seul test à l'import pour savoir qu'un apworld est cassé.

## Contexte

Le test d'import (9.38) tire **une** seed au hasard. Un apworld qui échoue sur une seed sur dix peut le
passer, puis faire échouer les joueurs. Deux signaux existent déjà et ne remontent à aucun admin :

- **Le test de config d'un slot** (9.42, `RunSlotPreflightJobHandler`) : quand un joueur clique « Tester
  ma config ». Son échec est enregistré sur le slot et montré au joueur seulement.
- **L'échec d'une vraie génération** (9.40, 9.41) : `SessionLifecycleManager` parse l'erreur et
  l'attribue à des slots par leur nom ; `NotifyGenerationFailureJob` prévient le joueur fautif et le
  créateur de la run.

### La règle qui évite le bruit

Un joueur qui fait échouer sa génération avec **un YAML qu'il a modifié** a un problème de config, pas un
apworld cassé. Un admin n'a rien à faire. Seul l'échec d'un slot dont le YAML est **équivalent au YAML par
défaut du jeu**, sur **le hash servi par le jeu**, accuse l'apworld. C'est exactement le cas Crystal
Project : « YAML vierge, test en échec ».

## Critères d'acceptation métier

1. **Test de config.** Un test de config de slot en échec, avec un YAML équivalent au YAML par défaut du
   jeu et sur le hash que sert le jeu, ouvre (ou compte une récurrence sur) un incident de type « échec
   avec le YAML par défaut ».
2. **Vraie génération.** Un échec de génération attribué à un slot dont le YAML est équivalent au YAML par
   défaut, sur le hash servi, fait de même.
3. **YAML personnalisé.** Un échec avec un YAML modifié par le joueur n'ouvre **rien**.
4. **Ancien hash.** Un échec sur un hash que le jeu ne sert plus n'ouvre rien : l'apworld a déjà été
   remplacé.
5. **Échec non attribué.** Un échec de génération que le parseur n'attribue à aucun slot n'ouvre rien :
   on ne sait pas quel apworld accuser.
6. **Même suivi que 38.1.** Ces incidents se dédoublonnent, s'ignorent, se prennent en charge et
   déclenchent les alertes de 38.2 exactement comme ceux du test d'import. Le type est affiché
   différemment sur la page Santé.

## Critères d'acceptation techniques

7. **Nouveau type** `ApworldIncidentType::DefaultYamlFailure` (`default_yaml_failure`).
8. **Équivalence de YAML**, règle pure du domaine `GameSelection` : `DefaultYamlEquivalence::isEquivalent(
   array $playerYaml, array $defaultYaml): bool`, sur les YAML **parsés**. Ignore les commentaires, le
   champ `name` et les champs `description` et `requires` ; compare la section du jeu clé par clé. Deux
   pondérations identiques écrites dans un ordre différent sont équivalentes.
9. **Point d'entrée unique.** Une commande `ReportDefaultYamlFailure(gameId, apworldHash, playerYaml,
   error)` vérifie le hash servi et l'équivalence, puis délègue à `RecordApworldIncident`. Les deux
   sources l'appellent, aucune ne réimplémente la règle.
10. **Source 1.** `RunSlotPreflightJobHandler::record()` appelle la commande quand le statut est `failed`,
    via un message asynchrone dispatché **après** son flush (le handler ne doit pas rallonger sa
    transaction).
11. **Source 2.** Après le flush du crash dans `SessionLifecycleManager`, pour chaque slot attribué :
    retrouver le slot de run (runs perso : `RunParticipant`, par l'id de slot) pour obtenir son YAML et
    son hash, puis dispatcher la même commande. Les sessions d'event et hebdomadaires sont traitées si
    leur YAML et leur hash sont retrouvables ; sinon elles sont ignorées, et c'est écrit dans le code.
12. **Parsing.** Un YAML de joueur illisible n'ouvre rien et se journalise en `warning`.
13. `composer gates` passe.

## Ordre TDD

1. `tests/Unit/GameSelection/DefaultYamlEquivalenceTest.php` :
   - `testIdenticalYamlIsEquivalent`
   - `testDifferentNameIsStillEquivalent`
   - `testReorderedWeightsAreEquivalent`
   - `testOneChangedWeightIsNotEquivalent`
   - `testAnAddedOptionIsNotEquivalent`
   - `testMissingGameSectionIsNotEquivalent`
2. `tests/Unit/GameSelection/ReportDefaultYamlFailureTest.php` :
   - `testDefaultYamlOnServedHashRecordsAnIncident`
   - `testCustomYamlRecordsNothing`
   - `testOldHashRecordsNothing`
   - `testUnreadableYamlRecordsNothingAndWarns`
3. `tests/Unit/PersonalRuns/RunSlotPreflightJobHandlerTest.php` :
   - `testFailedPreflightDispatchesTheReportAfterFlush`
   - `testPassedPreflightDispatchesNothing`
4. `tests/Unit/Sessions/SessionLifecycleManagerCrashTest.php` (ou l'existant) :
   - `testAttributedSlotDispatchesTheReportWithItsYamlAndHash`
   - `testUnattributedCrashDispatchesNothing`

## Tasks / Subtasks

- [x] **Task 0** - Vérifier comment retrouver YAML et hash d'un slot attribué pour chaque type de session
  (run perso, event, hebdo). Consigner la réponse dans les Dev Notes avant de coder la source 2.
- [x] **Task 1** (AC 7, 8) - Type d'incident et règle d'équivalence.
- [x] **Task 2** (AC 9, 12) - `ReportDefaultYamlFailure` et son message asynchrone.
- [x] **Task 3** (AC 10) - Branchement du test de config de slot.
- [x] **Task 4** (AC 11) - Branchement des vraies générations.
- [x] **Task 5** (AC 13) - Gates.

## Dev Notes

- **`SessionSlot` ne porte ni YAML ni hash** : seulement `gameId`, `slotName`, `slotId`. Pour une run
  perso, `SessionSlot.registrationId` contient l'id du joueur (voir `NotifyGenerationFailureJobHandler`)
  et le slot de run se retrouve dans `RunParticipant::getSlot()`. C'est l'objet de la Task 0.
- **Dédoublonnage.** Dix joueurs qui échouent avec le YAML par défaut le même soir font **un** incident
  avec dix occurrences, grâce à `RecordApworldIncident`.
- **Lien avec 38.7.** `DefaultYamlEquivalence` sera réutilisée par 38.7 pour reconnaître un YAML « jamais
  modifié ». La placer dans le domaine de `GameSelection`, pas dans `PersonalRuns`.

### References

- [Source: api/src/PersonalRuns/Application/Handler/RunSlotPreflightJobHandler.php]
- [Source: api/src/Sessions/Application/Service/SessionLifecycleManager.php] - crash, parsing et dispatch
- [Source: api/src/Sessions/Application/Handler/NotifyGenerationFailureJobHandler.php] - résolution slot vers joueur
- [Source: _bmad-output/implementation-artifacts/9-40-generation-failure-parsing-per-slot-attribution.md]

## Dev Agent Record

### Task 0 : retrouver le YAML et le hash d'un slot attribué

| Session | `SessionSlot.registrationId` | YAML | Hash |
|---|---|---|---|
| Run perso | id du joueur | slot du `RunParticipant` (par `slotId`) | hash du slot : celui avec lequel la run a été générée |
| Event | id de l'inscription | slot de la `Registration` (par `slotId`) | aucun : un event génère avec le hash que sert le jeu (`SessionOrchestrator`) |
| Hebdo | - | - | hors périmètre : générée depuis le template admin par `WeeklyRuns`, sans slot joueur, et ses échecs ne passent pas par `recordCrash` |

Un slot d'archive importée (story 16.18) a un `gameId` vide : il est ignoré.

### Écarts à la rédaction initiale

- **`DefaultYamlEquivalence` existait déjà**, créée par la 38.7. Ses tests couvrent l'AC 8.
- **Source 2 : un second handler sur `NotifyGenerationFailureJob`** (`ReportGenerationFailureToApworldHealthHandler`,
  contexte `Sessions`) plutôt qu'un ajout dans `SessionLifecycleManager`. Le job part déjà après le flush
  du crash et porte les slots attribués. `recordCrash()` reste inchangé.
- **Source 1 : seul le verdict du générateur accuse.** `RunSlotPreflightJobHandler` enregistre aussi en
  `failed` un runner indisponible et un test hors délai : ces deux cas ne disent rien de l'apworld et
  n'envoient rien.
- **Un YAML vide vaut le YAML par défaut**, puisque c'est avec lui que la génération tourne ; un slot sans
  hash est sur le hash servi.
- **La commande flushe elle-même** (`ReportDefaultYamlFailure` est l'unité de travail) et rend un
  `DefaultYamlFailureReport` (verdict + enregistrement). Le handler du message
  `ReportDefaultYamlFailureJob` déclenche ensuite les alertes 38.2 par `ApworldIncidentAlertDispatcher::dispatchOpened()`.
  Une récurrence n'alerte personne.
- **Cycle de vie du type** : `default_yaml_failure` suit l'apworld servi (fermé automatiquement quand le
  jeu change de hash, comme `preflight_failed`) et n'est pas refermé par un test d'import qui passe : un
  apworld qui échoue une seed sur dix passe justement ce test.

### Défaut trouvé en route

- **`StaffAlertFactory::typeLabel()`** est un `match` exhaustif sans branche par défaut : le nouveau type
  aurait levé une `UnhandledMatchError` à la première alerte Discord. Couvert par un test, puis corrigé.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `ReportDefaultYamlFailure` | 8 tests, commande qui rend toujours `UnknownGame` : 8 échecs | 8 verts |
| `ReportDefaultYamlFailureHandler` | 3 tests, `__invoke` vide : 2 erreurs (aucun incident) | 3 verts |
| Source 1 (`RunSlotPreflightJobHandler`) | 3 tests ajoutés : 1 échec (les 2 cas « rien » passent déjà) | 9 verts |
| Source 2 | 5 tests, `__invoke` vide : 3 échecs | 5 verts |
| Libellé Discord | 1 test : `UnhandledMatchError` | vert |
| Front (page Santé, notification) | 2 tests : 2 échecs | verts |

### Vérifications

- `composer gates` vert (2190 tests), `pnpm gates` vert (532 tests, les 10 warnings de develop).
- `debug:messenger` : `NotifyGenerationFailureJob` a ses deux handlers ; `ReportDefaultYamlFailureJob` est routé sur `async`.
- Pas d'e2e sur une stack locale.

### File List

- `api/src/GameSelection/Domain/Enum/ApworldIncidentType.php`
- `api/src/GameSelection/Application/Command/ReportDefaultYamlFailure.php`, `DefaultYamlFailureReport.php`, `DefaultYamlFailureVerdict.php` (nouveaux)
- `api/src/GameSelection/Application/Message/ReportDefaultYamlFailureJob.php` (nouveau)
- `api/src/GameSelection/Application/Handler/ReportDefaultYamlFailureHandler.php` (nouveau)
- `api/src/GameSelection/Application/Support/ApworldIncidentAlertDispatcher.php`, `StaffAlertFactory.php`
- `api/src/PersonalRuns/Application/Handler/RunSlotPreflightJobHandler.php`
- `api/src/Sessions/Application/Handler/ReportGenerationFailureToApworldHealthHandler.php` (nouveau)
- `api/config/packages/messenger.yaml`
- tests : `ReportDefaultYamlFailureTest`, `ReportDefaultYamlFailureHandlerTest`, `RunSlotPreflightJobHandlerTest`,
  `ReportGenerationFailureToApworldHealthHandlerTest`, `StaffAlertFactoryTest`
- `frontend/src/features/admin/admin-apworld-health-api.ts`, `apworld-incident-list.test.tsx`
- `frontend/src/features/community/notification-center.tsx` (+ test)

## Corrections de revue (2026-09-26)

- **Un résultat de test porte l'apworld qu'il a testé.** `RunSlotPreflightJob` gagne `apworldHash`, fixé à
  l'envoi et gardé à chaque relance. Un résultat obtenu sur un autre apworld que celui du slot est écarté :
  un test lancé sur l'ancienne version et fini après la bascule de la 38.7 (même YAML, donc même `yamlSha`)
  n'arrive plus sur la nouvelle, ni n'ouvre d'incident contre elle. Règle aussi la limite connue notée en 38.7.
  Un job mis en file avant ce champ (null) reste traité comme avant.
- **Pour un event, l'apworld servi au moment du crash** : `SessionLifecycleManager` le capture pour chaque jeu
  de la session et le passe dans `NotifyGenerationFailureJob`. Une promotion entre le crash et le job
  n'endosse plus l'échec.
- **Lecture de YAML partagée** avec la 38.7 (`YamlDocumentReader`).
