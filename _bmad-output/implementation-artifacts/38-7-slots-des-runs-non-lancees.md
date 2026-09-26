# Story 38.7: Slots des runs non lancées

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-24
**Dépend de :** 38.6 (`ApworldPromotedEvent`), 38.4 (`DefaultYamlEquivalence`).

## Story

En tant que joueur,
je veux que mon slot passe à la nouvelle version d'un jeu tant que ma run n'est pas lancée, **sans perdre
la config que j'ai faite**, et être prévenu si cette config n'est plus valable,
afin de ne pas jouer sur une version cassée ni retrouver mes réglages écrasés.

## Contexte

Chaque slot de run perso enregistre le hash de l'apworld à sa création, et le garde à chaque sauvegarde
(`PersonalRunGameSelection::diffSlots()`, `$matched['apworldHash'] ?? $game->getApworldHash()`). Le test
de config (`RunSlotPreflightJobHandler`) et le lancement (`LaunchPersonalRunJobHandler`) utilisent ce hash.
C'est voulu par la story 3.10 (AC3, « version snapshot ») ; la conséquence, vue sur Crystal Project, est
qu'un slot créé avant la correction reste sur la version cassée, même après le bon import.

**Les events ne figent pas le hash** : à la génération, `SessionOrchestrator` (autour de la ligne 595)
prend `$game->getApworldHash()`, avec le YAML enregistré du joueur. Un joueur d'event suit donc déjà la
version courante, mais personne ne vérifie que son YAML reste valable.

Décision de Jean : un slot suit la version courante **tant que sa run n'est pas lancée**, puis se fige ; un
YAML personnalisé qui reste valable ne doit **jamais** être remplacé ; si le YAML n'est plus valable, la
version est **quand même** mise à jour et le joueur est prévenu.

## La règle des trois cas

À la promotion d'un nouvel apworld pour un jeu, chaque slot concerné est classé :

| Cas | Situation | YAML | Version du slot | Joueur |
|---|---|---|---|---|
| 1 | YAML vide, ou équivalent à l'**ancien** YAML par défaut | remplacé par le **nouveau** YAML par défaut | mise à jour | rien |
| 2 | YAML personnalisé, et **chaque option réglée** existe encore avec une valeur acceptée | **conservé tel quel** | mise à jour | rien |
| 3 | YAML personnalisé, et au moins une option n'existe plus ou a une valeur refusée | **conservé tel quel** | mise à jour | prévenu, slot « à revoir » |

Au cas 2, une option ajoutée par la nouvelle version prend sa valeur par défaut : le YAML du joueur, relu
par la nouvelle version, donne le même résultat que ce qu'il avait réglé, plus les nouveautés par défaut.

## Critères d'acceptation métier

1. **Périmètre, runs perso.** Les slots de runs perso **pas encore lancées** (statut `draft`) sur le jeu
   promu, dont le hash est l'ancien hash du jeu ou est absent, sont classés et traités selon la règle.
   Une run lancée, en cours ou terminée n'est **jamais** touchée.
2. **Périmètre, events.** Les inscriptions à un event **pas encore généré** sont classées de la même façon.
   Leur hash suit déjà le jeu : seuls le remplacement du cas 1 et l'avertissement du cas 3 s'appliquent.
3. **Cas 3, avertissement.** Le joueur reçoit une notification in-app « {jeu} a été mis à jour : ta config
   est à revoir », qui mène à sa sélection et liste les options en cause. Le slot affiche un badge
   « À revoir » jusqu'à ce que le joueur sauvegarde de nouveau ce slot.
4. **Test de config.** Le résultat du test de config d'un slot traité est remis à zéro (il portait sur
   l'ancienne version), et le test est relancé automatiquement.
5. **Nouveaux slots.** Inchangé : un slot créé après la promotion prend la version courante, comme
   aujourd'hui.
6. **Figé au lancement.** Au lancement d'une run, les slots gardent leur hash jusqu'à la fin de la run,
   comme le prévoit la story 3.10.

## Critères d'acceptation techniques

7. **Classement, règle pure** : `SlotYamlCompatibility` (domaine de `GameSelection`),
   `classify(array $playerYaml, array $oldDefaultYaml, array $newOptionTypes): SlotYamlVerdict`, avec
   `SlotYamlVerdict` = cas (`replace_with_default`, `keep`, `needs_review`) + liste des options en cause.
   Réutilise `DefaultYamlEquivalence` (38.4) pour le cas 1.
8. **Validité d'une option** (cas 2 contre 3), d'après les types effectifs du jeu
   (`Game::getEffectiveOptionTypes()`) : option connue, choix connu pour un `Choice`, valeur dans les
   bornes pour un `Range`, sous-clés connues pour un `OptionDict` qui en déclare. Une valeur spéciale
   d'Archipelago (`random`, `random-low`, `random-high`, `random-range-*`) est acceptée. Une option dont
   le type est inconnu est acceptée : dans le doute, on ne dérange pas le joueur.
9. **Application.** `ResyncSlotsAfterApworldPromotionHandler` consomme `ApworldPromotedEvent` (38.6) :
   runs perso `draft` via `RunParticipantRepositoryInterface`, inscriptions d'event non générées via le
   dépôt des inscriptions. Une méthode de domaine par agrégat applique le résultat (`RunParticipant::
   upgradeSlotApworld(slotId, newHash, ?newYaml, needsReview)` et son équivalent côté inscription).
10. **Une transaction par run ou par inscription**, pas une transaction géante : une erreur sur une run ne
    bloque pas les autres, elle est journalisée.
11. **Notifications et tests de config** dispatchés après chaque flush (AC-A4) : notification
    `slot_yaml_needs_review` (nouveau type, rendu dans `notification-center.tsx`), `RunSlotPreflightJob`
    pour chaque slot de run perso traité.
12. **Badge.** `needsReview` est stocké dans le JSON du slot (`game_slots`), exposé par la sélection et
    effacé par la sauvegarde suivante du slot. Pas de migration de schéma, le champ est optionnel.
13. **YAML illisible.** Un YAML de joueur qui ne se parse pas est classé cas 3, sans liste d'options, avec
    un message dédié : on ne l'écrase pas, on prévient.
14. `composer gates` et `pnpm gates` passent.

## Ordre TDD

1. `tests/Unit/GameSelection/SlotYamlCompatibilityTest.php` :
   - `testEmptyYamlIsReplacedByTheNewDefault`
   - `testYamlEquivalentToTheOldDefaultIsReplaced`
   - `testCustomYamlWithValidOptionsIsKept`
   - `testRemovedOptionNeedsReviewAndIsListed`
   - `testUnknownChoiceValueNeedsReview`
   - `testOutOfRangeValueNeedsReview`
   - `testRandomSpecialValuesAreAccepted`
   - `testOptionOfUnknownTypeIsAccepted`
   - `testUnreadableYamlNeedsReviewWithoutOptions`
2. `tests/Unit/PersonalRuns/RunParticipantUpgradeSlotTest.php` :
   - `testUpgradeReplacesHashAndYamlAndClearsThePreflight`
   - `testUpgradeKeepsTheYamlWhenNoneIsGiven`
   - `testNeedsReviewIsClearedByTheNextSave`
3. `tests/Unit/GameSelection/ResyncSlotsAfterApworldPromotionHandlerTest.php` :
   - `testOnlyDraftRunsAreTouched`
   - `testSlotsOnAnotherHashAreLeftAlone`
   - `testNeedsReviewNotifiesThePlayerAfterFlush`
   - `testEverySyncedSlotGetsANewPreflight`
   - `testAFailingRunDoesNotStopTheOthers`
4. Frontend : badge « À revoir » sur la sélection, rendu de la notification.

## Tasks / Subtasks

- [x] **Task 1** (AC 7, 8, 13) - `SlotYamlCompatibility`, `SlotYamlVerdict`, tests.
- [x] **Task 2** (AC 9, 12) - Méthodes de domaine sur `RunParticipant` et sur l'inscription d'event.
- [x] **Task 3** (AC 1, 2, 9, 10) - Handler de resynchronisation.
- [x] **Task 4** (AC 3, 4, 11) - Notification et relance du test de config.
- [x] **Task 5** (AC 3, 12) - Badge et effacement à la sauvegarde, côté API et frontend.
- [x] **Task 6** (AC 14) - Gates.

## Dev Notes

- **Story 3.10.** Cette story modifie son AC3 : le figeage de la version commence **au lancement de la
  run**, plus à la création du slot. Ajouter une note de renvoi dans la story 3.10.
- **`diffSlots()`** n'a pas besoin de changer : la resynchronisation met le hash à jour sur le slot
  lui-même, que `diffSlots()` conserve ensuite comme aujourd'hui.
- **Aller-retour YAML.** Ne jamais réécrire le YAML d'un joueur aux cas 2 et 3 : ni re-dump, ni
  normalisation. Un re-dump PHP perd la distinction liste vide / dictionnaire vide (voir le hotfix v0.20.3).
- **Types effectifs.** `getEffectiveOptionTypes()` fusionne l'introspection et la curation admin (9.52).
  Au moment de l'événement, le jeu est déjà promu : ses types sont ceux de la nouvelle version.

### References

- [Source: api/src/PersonalRuns/Application/Service/PersonalRunGameSelection.php] - `diffSlots()`, ligne 619
- [Source: api/src/Sessions/Application/Service/SessionOrchestrator.php] - hash courant pour les events, ligne 595
- [Source: _bmad-output/implementation-artifacts/3-10-apworld-source-of-truth-game-library.md] - AC3
- [Source: _bmad-output/implementation-artifacts/38-6-mise-a-jour-en-trois-temps.md]

## Dev Agent Record

### Écarts à la rédaction initiale (décidés à l'implémentation)

- **AC 9 : deux handlers, un par contexte**, pas un `ResyncSlotsAfterApworldPromotionHandler` unique dans
  `GameSelection`. Chaque contexte possède ses agrégats : `UpgradeRunSlotsAfterApworldPromotionHandler`
  (`PersonalRuns`) et `UpgradeRegistrationSlotsAfterApworldPromotionHandler` (`Registrations`)
  consomment le même message. Ce qu'ils partagent (quels slots sont concernés, que devient le YAML) vit
  dans `PromotedSlotYaml` (`GameSelection/Application/Service`), qui rend un `PromotedSlotDecision`.
- **Le message s'appelle `ApworldPromoted`** (`GameSelection/Application/Message`), construit depuis le
  record `ApworldPromotion` de la 38.6. Il part après le flush, depuis
  `ApworldIncidentAlertDispatcher::dispatchForDecisions()` (passe des 5 minutes et commande manuelle) et
  depuis `TriageApworldCandidate::forcePromote()`. Routé sur `async`.
- **AC 7 : `classify()` reçoit aussi le YAML par défaut de la nouvelle version.** Une option disparue se
  juge contre la section du jeu dans ce YAML : les types effectifs ne listent que les options typées, une
  option absente des types n'est pas forcément supprimée.
- **`DefaultYamlEquivalence` créé ici**, la 38.4 n'étant pas encore faite : elle le réutilisera. Deux
  YAML sont équivalents si leurs sections de jeu donnent le même tirage (poids nuls ignorés, une seule
  valeur possible ramenée à un scalaire), `name` et `description` exclus.
- **AC 2, périmètre events** : un event `draft` ou `published` **sans aucune session**. Dès qu'une session
  existe, les slots sont figés. Un slot d'inscription sans YAML reste sans YAML (le défaut est résolu à la
  génération) et suit seulement la version. Pas de test de config par slot côté events : il n'existe pas.
- **AC 3, lien de la notification.** Run : `/runs/{runId}/jeux`. Event :
  `/evenements/{eventId}/inscription/{registrationId}/recap`, parce que c'est le récap qui liste les
  slots avec leur YAML (la page « jeux » ne montre que la sélection). Le badge « À revoir »
  (`SlotNeedsReview`) est affiché sur la sélection de run, sur la vue du propriétaire par participant et
  sur le récap d'inscription.
- **Un slot sur une troisième version** (ni l'ancien hash, ni le nouveau) n'est pas touché : on ne déplace
  que la version qui vient d'être remplacée.

### Limite connue

- **Le test de config d'un slot de run ne vérifie que le `yamlSha`**, pas le hash d'apworld : un
  `RunSlotPreflightJob` lancé juste avant la promotion et terminé juste après peut écrire un verdict
  obtenu avec l'ancienne version. La fenêtre est de l'ordre de la minute, et le prochain enregistrement
  du slot relance le test. À traiter si on l'observe.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `DefaultYamlEquivalence` | 8 tests, classe vide | 8 verts |
| `SlotYamlCompatibility` | 12 tests, classe vide | 12 verts |
| `RunParticipant::upgradeSlotApworld` | 6 tests, méthode absente | 6 verts |
| `Registration::upgradeSlotApworld` | **rouge non observé** : tests écrits après la méthode | 3 verts |
| Handler runs | 7 tests, `__invoke` vide : 5 échecs comportementaux | 7 verts |
| Handler inscriptions | 6 tests, `__invoke` vide : 3 échecs | 6 verts, puis refactor commun `PromotedSlotYaml` |
| Émission de `ApworldPromoted` | 3 tests existants mis à jour : 2 échecs (+1 fonctionnel) | verts |
| Notification front | 3 tests : 3 échecs | verts |
| `SlotNeedsReview` | 4 tests, composant vide : 3 échecs | verts |

Le test fonctionnel `PersonalRunGameSelectionPayloadTest::testASlotToReviewCarriesItsReasons` est passé
directement : la sélection fusionnait déjà le slot entier. Il reste comme test de caractérisation, et
couvre l'aller-retour de `needsReview` dans le JSON en base.

### Vérifications

- `composer gates` : vert (2170 tests). `pnpm gates` : vert (530 tests), les 10 warnings de lint sont
  ceux de `develop`.
- `debug:messenger` : `ApworldPromoted` est consommé par les deux handlers ; `lint:container` vert.
- **Pas d'e2e sur une stack locale** pour cette story : le chemin promotion vers slots est couvert par les
  tests unitaires des handlers et les tests de l'émission.

### File List

- `api/src/GameSelection/Domain/Service/DefaultYamlEquivalence.php` (nouveau)
- `api/src/GameSelection/Domain/Service/SlotYamlCompatibility.php` (nouveau)
- `api/src/GameSelection/Domain/Enum/SlotYamlCase.php`, `SlotYamlProblem.php` (nouveaux)
- `api/src/GameSelection/Domain/ValueObject/SlotYamlIssue.php`, `SlotYamlVerdict.php` (nouveaux)
- `api/src/GameSelection/Application/Message/ApworldPromoted.php` (nouveau)
- `api/src/GameSelection/Application/Service/PromotedSlotYaml.php`, `PromotedSlotDecision.php` (nouveaux)
- `api/src/GameSelection/Application/Support/ApworldIncidentAlertDispatcher.php`
- `api/src/GameSelection/Application/Command/TriageApworldCandidate.php`
- `api/src/PersonalRuns/Domain/Entity/RunParticipant.php`
- `api/src/PersonalRuns/Application/Handler/UpgradeRunSlotsAfterApworldPromotionHandler.php` (nouveau)
- `api/src/PersonalRuns/Application/Service/PersonalRunGameSelection.php`
- `api/src/Registrations/Domain/Entity/Registration.php`
- `api/src/Registrations/Application/Handler/UpgradeRegistrationSlotsAfterApworldPromotionHandler.php` (nouveau)
- `api/config/packages/messenger.yaml`
- tests : `DefaultYamlEquivalenceTest`, `SlotYamlCompatibilityTest`, `RunParticipantUpgradeSlotTest`,
  `RegistrationUpgradeSlotTest`, `UpgradeRunSlotsAfterApworldPromotionHandlerTest`,
  `UpgradeRegistrationSlotsAfterApworldPromotionHandlerTest`, `ReconcileApworldIncidentsHandlerTest`,
  `ReconcileApworldIncidentsCommandTest`, `TriageApworldCandidateTest`,
  `AdminApworldCandidateControllerTest`, `PersonalRunGameSelectionPayloadTest`
- `frontend/src/features/games/slot-needs-review.tsx` (+ test, nouveaux)
- `frontend/src/features/community/notification-center.tsx` (+ test)
- `frontend/src/features/personal-runs/personal-run-game-selection-page.tsx`,
  `personal-run-participant-detail-page.tsx`, `personal-runs-api.ts`, `types.ts`
- `frontend/src/features/events/events-api.ts`, `registration-recap-gate.tsx`

## Corrections de revue (2026-09-26)

- **Une vraie unité de travail par run et par event**, en deux temps : chaque slot est d'abord décidé sans
  rien toucher, puis tout est appliqué et enregistré d'un coup. Une erreur de classement ne laisse plus
  de modification à moitié faite en mémoire, qu'un flush suivant écrirait ; et un slot qui change
  d'apworld sans test ni avis (pas de YAML, pas de défaut) est bien enregistré.
- **Une seule lecture de YAML** : `Shared\Application\Support\YamlDocumentReader` (BOM, document illisible
  ou vide, document qui n'est pas un mapping), partagée avec la 38.4.
