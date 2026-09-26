# Story 38.9: Test tournant du catalogue

**Status:** review
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

- [x] **Task 1** (AC 8) - `CatalogSweepPlanner`, `SweepCandidate`, tests.
- [x] **Task 2** (AC 9) - `ApworldHealth`, dépôt, migration.
- [x] **Task 3** (AC 4-6, 10) - Confirmation et régression d'image dans la réconciliation.
- [x] **Task 4** (AC 1-3, 11-14) - Message, handler, réglage, commande console.
- [x] **Task 5** (AC 7) - Avancement du cycle sur la page Santé.
- [x] **Task 6** (AC 15) - Gates.

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

## Dev Agent Record

### Écarts à la rédaction initiale

- **AC 8 : `plan()` reçoit la référence et l'identifiant de l'image courante**, pas l'identifiant seul : la
  règle réutilise `ArchipelagoImageFreshness` (38.8), qui a besoin des deux. Un verdict dont la fraîcheur est
  **inconnue** (même tag sans les deux identifiants, verdict antérieur à la 38.8) passe en priorité 1, comme
  une autre image. Un apworld servi par deux jeux n'est testé qu'une fois.
- **AC 3 : exclusions élargies.** En plus des jeux désactivés, des candidats en test et des verdicts forcés :
  un test déjà en cours (`pending`), un verdict sauté (`skipped`, pas de template, relancer ne changerait
  rien) et un apworld que l'orchestrateur ne liste pas (il ne pourrait pas tourner). La désactivation vient
  de `ServedApworld::$disabled`, lu par la même requête DBAL.
- **AC 9 : `ApworldHealth` stocke le `checkedAt` du verdict comme identifiant**, en chaîne, comme
  l'observation des incidents (38.1). Une ligne est créée au premier verdict terminé lu.
- **AC 5 : la régression d'image exige une image connue des deux côtés.** Un succès antérieur à la 38.8 (sans
  image) ne permet pas d'affirmer que l'image a changé : l'incident est alors « test en échec ».
- **AC 6 : un succès résout aussi un incident de régression d'image**, pas seulement « test en échec ».
- **AC 10 : la relance part après le flush**, comme tout effet de bord ; `ReconcileApworldIncidentsResult` la
  rapporte (`retriedApworldHashes`).
- **AC 7 : avancement** exposé par `GET /api/v1/admin/apworld-incidents/sweep-progress` (`CatalogSweepProgress`)
  et affiché en tête de la page Santé : image en service, apworlds déjà testés dessus sur le total (jeux
  activés, un apworld servi par deux jeux compté une fois). Rien n'est affiché si le runner ne dit pas quelle
  image tourne.
- **Libellés** : « Régression après changement d'image » sur Discord (`StaffAlertFactory`, dont le `match`
  exhaustif aurait sinon levé une `UnhandledMatchError`, attrapé par PHPStan), sur la page Santé et dans la
  notification.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `CatalogSweepPlanner` | 8 tests, `plan()` vide : 8 échecs | verts |
| `ApworldHealth` | 4 tests, entité vide : 3 échecs | verts |
| Réconciliation (confirmation, régression) | 5 tests : 2 échecs (les 3 autres passaient déjà par le comportement 38.1) | 19 verts |
| Libellé Discord | 1 test : `UnhandledMatchError` | vert |
| `SweepApworldCatalog` | 6 tests, `sweep()` vide : 4 échecs | verts |
| Requête DBAL (désactivé) | 1 test fonctionnel : 1 échec | vert |
| Planification 05:00 | 1 test : message non planifié | vert |
| Handler et commande console | 3 tests, squelettes : 3 échecs | verts |
| `CatalogSweepProgress` et route | 2 tests unitaires + 2 fonctionnels : échecs | verts |
| Front (avancement, libellés) | 2 + 2 tests : échecs | verts |

### Vérifications

- `composer gates` vert (2268 tests), `pnpm gates` vert (543 tests, les 10 warnings de develop).
- Migration `Version20260926120000` validée sur une copie de la base : le diff Doctrine ne remonte que les
  faux positifs connus.
- **E2e local (2026-09-26)**, vrai orchestrateur : `app:apworld-sweep:run --batch=2` a pris en priorité deux
  verdicts d'image inconnue (Castlevania: Aria of Sorrow, Beat Saber) ; Castlevania passe, Beat Saber échoue et,
  n'ayant jamais passé, ouvre un incident au premier échec ; `apworld_health` rempli (7 lignes, 2 avec l'image
  du dernier succès).
