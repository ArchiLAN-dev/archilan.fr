# Story 38.6: Mise à jour en trois temps

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-24
**Dépend de :** 38.1 (réconciliation et incidents), 38.5 (liste des mises à jour disponibles).

## Story

En tant qu'admin d'ArchiLAN,
je veux qu'une nouvelle version d'apworld, importée à la main ou trouvée par la veille, ne devienne la
version servie aux joueurs **qu'après** avoir passé le test de génération,
afin qu'un apworld cassé ne remplace plus jamais un apworld qui marche.

## Contexte

Aujourd'hui, `AdminGameLibrary::configureApworld()` appelle `Game::configureApworld()` dès le retour du
téléversement : le jeu sert le nouvel apworld **pendant** que le test tourne, et le garde s'il échoue.
C'est ce qui a laissé Crystal Project v0.17.0 en production pendant deux mois.

L'orchestrateur et MinIO étant adressés par hash, un apworld peut être téléversé et testé **sans être
servi**. Cette story introduit donc un **candidat** : la version en attente de verdict, portée par le jeu
à côté de la version servie.

Jean a tranché : le circuit s'applique **à tous les jeux** suivis sur GitHub, **sans période de gel**, et
**aussi à l'import manuel**, avec un forçage admin.

## Critères d'acceptation métier

1. **Import manuel.** Un import (fichier ou GitHub) crée un candidat. Le jeu continue de servir sa version
   actuelle. La page du jeu affiche « Nouvelle version en test » avec la version et l'heure.
2. **Promotion.** Quand le test du candidat passe, il devient la version servie : hash, YAML par défaut,
   types d'options, noms de lieux et version déployée sont mis à jour ensemble.
3. **Rejet.** Quand le test échoue (ou ne peut pas tourner, faute de template), le candidat est rejeté :
   le jeu garde sa version, un incident « mise à jour rejetée » s'ouvre avec la version et l'erreur, et
   la page du jeu montre le rejet.
4. **Forçage.** Sur un candidat rejeté ou en test, un admin peut forcer la promotion, après
   confirmation. Le forçage est tracé (qui, quand) et marque le verdict comme forcé (story 9.38 AC4), ce
   qui n'ouvre pas d'incident de test en échec (38.1 AC 7).
5. **Premier apworld.** Un jeu qui n'a encore aucun apworld suit le même circuit : il devient jouable à
   la promotion, pas avant.
6. **Mise à jour automatique.** Chaque nuit, après la veille (38.5), chaque jeu en « mise à jour
   disponible » dont la release contient **un seul** apworld utilisable reçoit un candidat, sans action
   humaine. Tous les jeux suivis sur GitHub sont concernés.
7. **Pas d'acharnement.** Une version rejetée n'est pas re-tentée automatiquement tant qu'une nouvelle
   version ne sort pas. Un admin peut la relancer à la main.
8. **Ambiguïté.** Si la release contient plusieurs apworlds et que le filtre de la source ne désigne pas
   un seul fichier, aucune mise à jour n'est faite et un incident « mise à jour à arbitrer » s'ouvre.
9. **Un candidat à la fois.** Un nouvel import pendant qu'un candidat est en test remplace ce candidat.
10. **Délai.** Un candidat sans verdict au bout de 30 minutes est rejeté pour délai dépassé.
11. **Annonce.** Chaque promotion automatique est annoncée sur le salon Discord staff (38.2) : jeu,
    ancienne et nouvelle version, lien vers la release. Le staff peut ainsi prévenir les joueurs si le
    mod client change.
11 bis. **Plafond par nuit** (ajouté le 2026-09-25, après le premier passage réel de la veille de 38.5 :
    **233** mises à jour en attente, la veille n'ayant pas tourné depuis mai). Au plus N candidats
    automatiques par nuit, réglable (`APWORLD_AUTO_UPDATE_BATCH_SIZE`, **10 par défaut**, décision de Jean le 2026-09-25), les
    jeux qui attendent depuis le plus longtemps d'abord. Le reste passe les nuits suivantes. Sans ce
    plafond, la première nuit enverrait 233 tests à l'orchestrateur et 233 annonces sur Discord.

## Critères d'acceptation techniques

12. **Value object `ApworldCandidate`** (`GameSelection/Domain/ValueObject/`), embarqué dans `Game`
    (`#[ORM\Embedded]`, colonnes `candidate_*`) : hash, clé de stockage, clé MinIO, YAML par défaut, nom
    Archipelago, types d'options, noms de lieux, tag de version (nullable), origine (`manual` ou `auto`),
    date de soumission, id de l'admin (nullable), statut (`testing` ou `rejected`), erreur de rejet.
13. **Méthodes de domaine** sur `Game`, pures : `submitApworldCandidate(ApworldCandidate)`,
    `promoteApworldCandidate(now, ?forcedBy): ApworldPromotion`, `rejectApworldCandidate(error, now)`,
    `hasCandidateInTest()`. `ApworldPromotion` est un record (ancien hash, nouveau hash, ancien YAML par
    défaut, ancienne et nouvelle version) : c'est l'entrée de 38.7.
14. **Circuit unique.** `AdminGameLibrary::configureApworld()` et `importFromGithub()` soumettent un
    candidat au lieu d'appeler `Game::configureApworld()`. Le téléversement à l'orchestrateur et le dépôt
    MinIO restent identiques. `Game::configureApworld()` ne sert plus qu'à la promotion.
15. **Décision dans la réconciliation.** `ReconcileApworldIncidents` (38.1), renommée si besoin en
    réconciliation apworld, traite aussi les candidats en test : `passed` promeut, `failed` et `skipped`
    rejettent, `pending` au-delà du délai rejette. Même règle qu'en 38.1 : runner indisponible, rien ne
    change.
16. **Après promotion**, après le flush : dispatch de `ApworldPromotedEvent` (message asynchrone) que
    consommeront 38.7 (slots) et 38.2 (annonce Discord).
17. **Mise à jour automatique.** `SubmitAvailableApworldUpdates` (`Application/Command/`), lancée par le
    message de 38.5 juste après `checkAll()` : pour chaque jeu du rapport, télécharge l'asset, soumet un
    candidat d'origine `auto`, sauf si ce tag a déjà été rejeté (AC 7) ou si l'asset est ambigu (AC 8).
18. **Nouveaux types d'incident** : `update_rejected`, `update_ambiguous`. `update_rejected` est clé sur
    le hash du **candidat**, pour qu'une nouvelle version ouvre un nouvel incident.
19. **Endpoints admin** : `POST /api/v1/admin/games/{id}/apworld-candidate/promote` (forçage, confirmation
    côté UI) et `POST .../apworld-candidate/retry` (relancer le test d'un candidat rejeté).
20. **Frontend.** `admin-game-editor.tsx` affiche la version servie et, séparément, le candidat (en test,
    rejeté avec son erreur), avec les boutons forcer et relancer.
21. **Migration** : colonnes `candidate_*` sur `game`, toutes nullables.
22. `composer gates` et `pnpm gates` passent.

## Ordre TDD

1. `tests/Unit/GameSelection/GameApworldCandidateTest.php` :
   - `testSubmittingACandidateDoesNotChangeTheServedApworld`
   - `testPromotionServesTheCandidateAndReturnsThePreviousVersion`
   - `testPromotionOfAGameWithoutApworldMakesItPlayable`
   - `testRejectionKeepsTheServedApworldAndRecordsTheError`
   - `testANewSubmissionReplacesTheCandidateInTest`
   - `testPromotingWithoutCandidateThrows`
2. `tests/Unit/GameSelection/ReconcileApworldCandidatesTest.php` :
   - `testPassedVerdictPromotesAndDispatchesTheEventAfterFlush`
   - `testFailedVerdictRejectsAndOpensAnUpdateRejectedIncident`
   - `testSkippedVerdictRejects`
   - `testPendingPastTheDeadlineRejectsForTimeout`
   - `testRunnerUnavailableChangesNothing`
3. `tests/Unit/GameSelection/SubmitAvailableApworldUpdatesTest.php` :
   - `testSubmitsAnAutoCandidateForAnUpdateAvailable`
   - `testSkipsATagAlreadyRejected`
   - `testAmbiguousReleaseOpensAnIncidentAndSubmitsNothing`
4. `tests/Unit/GameSelection/AdminGameLibraryTest.php` (existant) :
   - `testUploadSubmitsACandidateInsteadOfSwapping`
   - `testForcedPromotionMarksTheVerdictOverridden`
5. `tests/Functional/GameSelection/AdminApworldCandidateControllerTest.php` : promotion forcée, relance,
   `409` sans candidat, `403` hors admin.
6. Frontend : rendu du candidat en test, rejeté, et des deux actions.

## Tasks / Subtasks

- [x] **Task 1** (AC 12, 13, 21) - `ApworldCandidate`, `ApworldPromotion`, méthodes de `Game`, migration.
- [x] **Task 2** (AC 1, 5, 9, 14) - Import manuel et GitHub vers candidat.
- [x] **Task 3** (AC 2, 3, 10, 15, 16, 18) - Décision dans la réconciliation.
- [x] **Task 4** (AC 4, 19) - Forçage et relance.
- [x] **Task 5** (AC 6-8, 17) - Soumission automatique après la veille.
- [x] **Task 6** (AC 11) - Annonce Discord des promotions.
- [x] **Task 7** (AC 20) - Frontend.
- [x] **Task 8** (AC 22) - Gates.

## Dev Notes

- **Téléverser n'est pas servir.** `RunnerGateway::uploadApworld()` renvoie hash, YAML, types d'options
  et noms de lieux : tout ce que le candidat doit garder jusqu'à la promotion. Ne rien relire à la
  promotion, sauf échec avéré.
- **Le template du candidat.** Le test tourne avec le template généré au téléversement, stocké par
  l'orchestrateur sous le hash du candidat. Une surcharge admin du YAML par défaut (story 9.45) sur
  l'**ancienne** version n'est pas reportée : la story 9.45 reste valable après promotion.
- **Forçage et verdict.** Forcer appelle `RunnerGateway::overrideApworldPreflight(hash, true)` avant de
  promouvoir : sans ça, 38.1 ouvrirait un incident « test en échec » sur la version tout juste forcée.
- **Ce que le joueur voit.** Rien ne change pour lui avant la promotion. Les slots sont traités en 38.7.

### References

- [Source: api/src/GameSelection/Application/Service/AdminGameLibrary.php] - `configureApworld()`, `importFromGithub()`
- [Source: api/src/GameSelection/Domain/Entity/Game.php] - `configureApworld()`
- [Source: _bmad-output/implementation-artifacts/38-1-incidents-apworld.md]
- [Source: _bmad-output/implementation-artifacts/38-5-veille-quotidienne-des-versions.md]

## Dev Agent Record

### Écarts à la rédaction initiale (décidés à l'implémentation)

- **AC 12, 13, 21 : un agrégat à part, pas un value object embarqué dans `Game`.** `ApworldCandidate`
  (`Domain/Entity/`, table `apworld_candidate`) plutôt que des colonnes `candidate_*` sur `game`. L'AC 7
  (« une version rejetée n'est pas re-tentée ») exige de se souvenir des candidats passés : un objet
  embarqué ne garde que le dernier. La table garde l'historique, et `hasRejectedVersion()` s'appuie dessus.
- **Types d'options et noms de lieux lus à la promotion**, pas au téléversement : l'introspection de
  l'orchestrateur tourne en arrière-plan après l'upload, ses valeurs peuvent y être incomplètes.
- **AC 15 : une commande dédiée**, `DecideApworldCandidates`, appelée par le handler de la passe des
  5 minutes **avant** la réconciliation des incidents : un jeu promu dans le passage est ensuite vu avec
  son nouvel apworld, et l'incident de la version quittée se ferme dans le même passage.
- **AC 16 : `ApworldPromotedEvent` n'est pas encore émis.** Un message sans handler échouerait dans le
  worker ; la 38.7 l'ajoutera avec son consommateur. `ApworldPromotion` (record) porte déjà ce qu'il lui
  faut : ancien hash, ancien YAML par défaut.
- **AC 17 : un job par jeu** (`SubmitAutoApworldUpdateJob`), pas un téléversement en série dans le
  scheduler : chaque upload prend des dizaines de secondes, et un échec n'arrête pas les autres. Le job
  n'écrase jamais un candidat soumis entre-temps par un admin.
- **AC 11 bis : plafond de 10 par nuit.** L'ordre est celui du rapport de la veille (le jeu vérifié le
  plus anciennement d'abord), pas « qui attend depuis le plus longtemps » : la veille ne garde pas la
  date d'apparition d'une mise à jour. Une release ambiguë ne consomme pas de place. `0` désactive la
  mise à jour automatique.
- **Seule la veille de nuit soumet** : le bouton « Vérifier les mises à jour » de la page catalogue reste
  une vérification.

### Deux défauts trouvés en route

- **Régression évitée sur la story 9.51.** La règle qui écarte un vocabulaire de dict à moins de deux
  valeurs ne tournait qu'au téléversement. En déplaçant la bascule à la promotion, elle aurait disparu
  sans bruit. Elle vit maintenant dans `ApworldIntrospectionNormalizer`, appliquée à la promotion ; ses
  trois tests ont été déplacés tels quels, et un test de promotion la couvre.
- **Annonce de promotion non routée.** Le test fonctionnel du forçage a montré que
  `PostApworldPromotionToStaffChannelJob` partait en synchrone, dans la requête HTTP de l'admin : routé
  sur `async`.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| Agrégat `ApworldCandidate` | 9 échecs sur 11 | 11 tests |
| Types d'incident de mise à jour | constantes absentes, puis `match` non exhaustif signalé par PHPStan | 3 + 3 tests |
| Annonce de promotion (fabrique) | méthode absente | 12 tests |
| `SubmitApworldCandidate` | 6 échecs sur 6 | 6 tests |
| `DecideApworldCandidates` et `PromoteApworldCandidate` | 8 échecs sur 8, puis la règle 9.51 | 9 tests |
| Passe des 5 minutes et commande console | constructeurs sans la décision | 7 + 3 tests |
| Handler d'annonce | 3 échecs sur 4 | 4 tests |
| Upload fonctionnel (contrat changé) | « le jeu bascule » devenu « un candidat en test » | 2 tests |
| Persistance des candidats | service absent | 4 tests |
| `TriageApworldCandidate` | 6 échecs sur 7, puis un défaut d'ordre (entité modifiée avant l'accord du runner) | 7 tests |
| Endpoints forcer / relancer | routes absentes, puis routage manquant | 5 tests |
| Mise à jour automatique (sélection, plafond, ambiguïté) | 6 échecs sur 6, puis normalisation des tags | 6 tests |
| Job de soumission automatique | 2 échecs sur 3 | 3 tests |
| Veille de nuit vers mise à jour | constructeur sans la soumission | 1 test |
| Frontend (client, composant, libellés) | tests rouges puis verts | 4 + 7 + 3 tests |

### Vérifications

- `composer gates` : vert, 2127 tests. `pnpm gates` : vert, 523 tests, build OK (les 10 avertissements
  de lint sont ceux de `develop`).
- Migration rejouée sur une base vierge : aucune différence avec le mapping.
- **Scénario de bout en bout** sur l'orchestrateur local et une copie de la base locale, par le vrai
  endpoint d'upload :
  1. Crystal Project servi en v0.17.0 ; réimport de la v0.17.0 : candidat en test, le jeu ne bascule pas.
  2. Verdict en échec : candidat **rejeté**, incident « mise à jour rejetée » ouvert, jeu inchangé.
  3. Import de la v0.18.2 : candidat en test, puis **promu** au passage suivant. Le jeu sert
     `3bf11e98…`, et dans le même passage l'incident « mise à jour rejetée » (réglé par la promotion) et
     l'incident « test en échec » de la v0.17.0 (plus servie) se ferment.
- **Non vérifié de bout en bout :** la mise à jour automatique de nuit contre le vrai GitHub. Son handler
  n'est joignable que par le scheduler de 4 h, et aucune commande ne le déclenche sans ajouter un point
  d'entrée de débogage. Ses étapes sont couvertes par des tests à GitHub simulé ; sa source, la veille,
  a été lancée en réel en 38.5.

## Corrections de revue (2026-09-26)

- **Une introspection muette ne vide plus les tables du jeu.** Le gateway lit une panne de l'orchestrateur
  comme une liste vide. Tout monde Archipelago ayant au moins les options communes et un lieu, une réponse
  vide veut dire « pas de réponse » : la promotion est reportée à la passe suivante, sans rien toucher
  (`ApworldIntrospectionUnavailableException`). Écart au plan de revue : les types ne sont **pas** stockés
  sur le candidat à l'import, l'introspection n'y étant pas encore finie (voir plus haut).
- **Le forçage lit l'introspection avant de forcer le verdict** sur l'orchestrateur, pour ne jamais laisser
  un verdict forcé sur un apworld non basculé. Il annonce aussi au salon staff les incidents de mise à jour
  qu'il ferme, comme le chemin automatique.
- **Pas de verdict en 30 minutes = candidat `expired`, pas `rejected`.** Un incident prévient toujours les
  admins, mais la version reste éligible : la nuit suivante la retente. Relancer et forcer marchent aussi
  sur un candidat expiré ; le front affiche « Test sans verdict ».
- **Une lecture des verdicts par passe**, partagée par la décision et la réconciliation (même instantané),
  et le verrou de passe de la 38.1 couvre aussi la décision.
- **La réconciliation tourne sur le worker `async`**, plus dans le scheduler.
