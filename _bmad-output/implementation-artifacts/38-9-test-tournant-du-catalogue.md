# Story 38.9: Test tournant du catalogue

**Status:** ready-for-dev
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-24
**Dépend de :** 38.1 (incidents), 38.8 (image des verdicts, image courante).

## Story

En tant qu'admin d'ArchiLAN,
je veux que chaque apworld du catalogue soit retesté régulièrement, par petits lots, en commençant par
ceux qui n'ont pas encore été testés sur l'image Archipelago actuelle,
afin de repérer une régression de notre image ou un apworld qui échoue par intermittence, sans attendre
qu'un joueur tombe dessus et sans saturer l'orchestrateur.

## Contexte

Un apworld n'est testé qu'une fois, à son import. Rien ne revérifie ensuite :

- **une nouvelle image Archipelago** (nouvelle version d'Archipelago, changement de nos scripts
  `generate_multiworld.py`) peut casser des apworlds qui passaient ;
- **une seed malchanceuse** peut faire passer à l'import un apworld qui échoue une fois sur dix ;
- **un verdict ancien** ne dit plus rien de l'état actuel.

Jean a écarté le retest global à chaque image au profit d'un test **tournant, en décalé** : un petit lot
chaque nuit, avec priorité aux jeux testés sur une ancienne image. Avec environ 250 jeux et un lot de 25,
le catalogue est couvert en une dizaine de jours ; une nouvelle image est validée en autant de nuits,
sans pic de charge.

## Critères d'acceptation métier

1. **Lot quotidien.** Chaque nuit, un lot de jeux est retesté. La taille du lot est réglable et vaut 25
   par défaut.
2. **Priorité.** Le lot se remplit dans cet ordre :
   1. les jeux dont le dernier verdict a été obtenu sur une **autre image** que l'image courante, ou sur
      une image inconnue ;
   2. puis les jeux jamais testés ;
   3. puis les jeux dont le verdict est le plus ancien.
3. **Exclusions.** Ne sont pas retestés : les jeux sans apworld, les jeux dont un candidat est en test
   (38.6), les verdicts forcés par un admin, et les jeux désactivés.
4. **Confirmation avant alerte.** Un jeu qui **passait** et échoue au test tournant n'ouvre pas
   d'incident tout de suite : il est retesté, et l'incident ne s'ouvre qu'au **deuxième échec
   consécutif**. Un jeu qui n'avait jamais passé ouvre un incident au premier échec, comme en 38.1.
5. **Régression d'image.** Si le jeu passait sur une image et échoue deux fois sur l'image courante,
   l'incident est de type « régression après changement d'image » et cite les deux images. Sinon, il est
   de type « test de génération en échec ».
6. **Retour au vert.** Un succès remet le compteur d'échecs à zéro et résout l'incident actif, comme en
   38.1.
7. **Visibilité.** La page Santé des apworlds (38.3) affiche l'avancement du cycle : image courante,
   nombre de jeux déjà testés sur cette image sur le total.

## Critères d'acceptation techniques

8. **Priorité, règle pure** : `CatalogSweepPlanner` (domaine de `GameSelection`),
   `plan(list<SweepCandidate> $games, string $currentImageId, int $batchSize): list<string>` qui renvoie
   les hash à tester. `SweepCandidate` est un record (gameId, hash, imageId du verdict, date du verdict,
   exclu ou non). Aucune lecture de base ni d'horloge dans la règle.
9. **Mémoire de santé.** Nouvelle entité `ApworldHealth` (une ligne par jeu et hash) : dernier statut
   connu, dernier succès (date, image), échecs consécutifs. Mise à jour par la réconciliation (38.1)
   à chaque nouveau verdict **terminé**, identifié par son `checkedAt` pour ne pas compter deux fois le
   même verdict.
10. **Confirmation.** La réconciliation consulte `ApworldHealth` avant d'appeler `RecordApworldIncident` :
    échec d'un hash qui a déjà passé et `consecutiveFailures` à 1, alors relance immédiate du test
    (`runApworldPreflight`) sans incident ; à 2 ou plus, incident. Nouveau type
    `ApworldIncidentType::ImageRegression`.
11. **Planification.** `SweepApworldCatalogMessage` chaque nuit à 05:00 (après la veille de 04:00 de
    38.5, pour ne pas retester un jeu sur le point d'être mis à jour). Le handler lit l'image courante
    (`fetchRuntime()`, 38.8) et les verdicts, appelle le planner, puis `runApworldPreflight(hash)` pour
    chaque hash retenu. Sans image courante ou sans verdicts (runner indisponible), rien n'est lancé.
12. **Réglage.** `APWORLD_SWEEP_BATCH_SIZE` (défaut 25), borné entre 1 et 200 ; une valeur hors bornes est
    ramenée dans les bornes et journalisée.
13. **Charge.** Aucun parallélisme ajouté côté API : les tests sont lancés en file et la limite
    `PreflightMaxConcurrent` de l'orchestrateur borne la charge réelle.
14. **Commande console** `app:apworld-sweep:run [--batch=N]` pour lancer un lot à la main, par exemple
    juste après le déploiement d'une nouvelle image.
15. `composer gates` et `pnpm gates` passent.

## Ordre TDD

1. `tests/Unit/GameSelection/CatalogSweepPlannerTest.php` :
   - `testVerdictsFromAnotherImageComeFirst`
   - `testUnknownImageCountsAsAnotherImage`
   - `testNeverTestedComeBeforeOldVerdictsOnTheCurrentImage`
   - `testOldestVerdictsFirstOnTheCurrentImage`
   - `testExcludedGamesAreNeverPlanned`
   - `testBatchSizeIsRespected`
2. `tests/Unit/GameSelection/ApworldHealthTest.php` :
   - `testFailureAfterASuccessCountsOneConsecutiveFailure`
   - `testSuccessResetsTheCounterAndRecordsTheImage`
   - `testTheSameVerdictIsNeverCountedTwice`
3. `tests/Unit/GameSelection/ReconcileApworldIncidentsTest.php` (étendu) :
   - `testFirstFailureOfAHashThatPassedRetriesWithoutIncident`
   - `testSecondConsecutiveFailureOpensAnImageRegressionWhenTheImageChanged`
   - `testSecondConsecutiveFailureOnTheSameImageOpensAPreflightFailed`
   - `testFirstFailureOfAHashThatNeverPassedOpensAnIncidentImmediately`
4. `tests/Unit/GameSelection/SweepApworldCatalogHandlerTest.php` :
   - `testLaunchesThePlannedPreflights`
   - `testDoesNothingWithoutTheCurrentImage`
   - `testBatchSizeIsClamped`

## Tasks / Subtasks

- [ ] **Task 1** (AC 8) - `CatalogSweepPlanner`, `SweepCandidate`, tests.
- [ ] **Task 2** (AC 9) - `ApworldHealth`, dépôt, migration.
- [ ] **Task 3** (AC 4-6, 10) - Confirmation et régression d'image dans la réconciliation.
- [ ] **Task 4** (AC 1-3, 11-14) - Message, handler, réglage, commande console.
- [ ] **Task 5** (AC 7) - Avancement du cycle sur la page Santé.
- [ ] **Task 6** (AC 15) - Gates.

## Dev Notes

- **Pourquoi retester tout de suite, et pas attendre le lot suivant.** Attendre le lot suivant, c'est
  attendre environ dix jours avant de confirmer une régression. La relance immédiate coûte un test de plus,
  seulement sur les jeux en échec.
- **Verdict et relance.** `runApworldPreflight()` repasse le verdict en `pending` : la réconciliation ne
  doit rien conclure d'un `pending` (déjà le cas depuis 38.1, AC 9).
- **Pourquoi pas dans l'orchestrateur.** Le planner a besoin des jeux, de leur désactivation et des
  candidats en test, qui vivent dans l'API. L'orchestrateur reste un exécutant.

### References

- [Source: _bmad-output/implementation-artifacts/38-1-incidents-apworld.md]
- [Source: _bmad-output/implementation-artifacts/38-8-version-image-dans-les-verdicts.md]
- [Source: orchestrateur/internal/config/config.go] - `PreflightMaxConcurrent`
