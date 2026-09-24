# Story 38.4: Incidents issus des vraies générations

**Status:** ready-for-dev
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

- [ ] **Task 0** - Vérifier comment retrouver YAML et hash d'un slot attribué pour chaque type de session
  (run perso, event, hebdo). Consigner la réponse dans les Dev Notes avant de coder la source 2.
- [ ] **Task 1** (AC 7, 8) - Type d'incident et règle d'équivalence.
- [ ] **Task 2** (AC 9, 12) - `ReportDefaultYamlFailure` et son message asynchrone.
- [ ] **Task 3** (AC 10) - Branchement du test de config de slot.
- [ ] **Task 4** (AC 11) - Branchement des vraies générations.
- [ ] **Task 5** (AC 13) - Gates.

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
