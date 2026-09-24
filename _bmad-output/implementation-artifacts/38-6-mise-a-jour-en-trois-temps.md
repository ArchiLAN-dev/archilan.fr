# Story 38.6: Mise à jour en trois temps

**Status:** ready-for-dev
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

- [ ] **Task 1** (AC 12, 13, 21) - `ApworldCandidate`, `ApworldPromotion`, méthodes de `Game`, migration.
- [ ] **Task 2** (AC 1, 5, 9, 14) - Import manuel et GitHub vers candidat.
- [ ] **Task 3** (AC 2, 3, 10, 15, 16, 18) - Décision dans la réconciliation.
- [ ] **Task 4** (AC 4, 19) - Forçage et relance.
- [ ] **Task 5** (AC 6-8, 17) - Soumission automatique après la veille.
- [ ] **Task 6** (AC 11) - Annonce Discord des promotions.
- [ ] **Task 7** (AC 20) - Frontend.
- [ ] **Task 8** (AC 22) - Gates.

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
