# Story 38.10: Une dérogation se lève quand le test passe

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-27
**Origine :** constat de Jean le 2026-09-27 sur The Wind Waker HD - « Test de génération réussi » et, juste
dessous, « Dérogation active : le jeu reste sélectionnable malgré le verdict ».

## Story

En tant qu'admin d'ArchiLAN,
je veux qu'une dérogation disparaisse d'elle-même quand la version passe son test,
afin qu'une version saine soit surveillée comme les autres et qu'un futur échec m'alerte.

## Contexte

La dérogation (« Autoriser malgré l'échec », story 9.38) rend un jeu sélectionnable malgré un test échoué. Elle
est posée à la main, ou par le forçage d'une version candidate encore en test ou en échec
(`TriageApworldCandidate::forcePromote`, story 38.6), et survit par conception à tout nouveau test : seul le
bouton la bascule. Une version forcée pendant son test, qui passe ensuite, garde donc une dérogation qui n'a plus
rien à autoriser, avec trois effets :

| # | Effet |
|---|---|
| 1 | le test tournant du catalogue (story 38.9) saute toute version dérogée : cette version n'est plus jamais retestée, même après un changement d'image |
| 2 | un échec ultérieur serait classé « ignoré » d'emblée (`ReconcileApworldIncidents`), sans alerte |
| 3 | la page affiche « Dérogation active » sous un test réussi |

## Critères d'acceptation

1. **Orchestrateur** : un verdict réussi lève la dérogation ; un verdict échoué ou sauté la garde (un jeu forcé
   reste sélectionnable).
2. **API** : le test tournant ne saute une version dérogée que si son verdict est un échec ; une version dérogée
   mais réussie est retestée, ce qui lève les dérogations restées en production.
3. **Front** : « Dérogation active » ne s'affiche que sur un test échoué ; le bouton « Retirer la dérogation »
   reste disponible tant qu'une dérogation existe.
4. `go test ./...`, `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `applyVerdict` (orchestrateur).
- [x] **Task 2** (AC 2) - `SweepApworldCatalog`.
- [x] **Task 3** (AC 3) - `overrideIsActive` (front).
- [x] **Task 4** (AC 4) - Gates.

## Dev Agent Record

| Étape | Rouge | Vert |
|---|---|---|
| `TestAPassedVerdictClearsTheOverride` (orchestrateur), `TestASkippedVerdictKeepsTheOverride` | dérogation gardée après un succès | levée ; gardée sur un verdict sauté ; `TestACompletedVerdictRecordsTheImageItRanOn` la garde sur un échec |
| `SweepApworldCatalogTest::testAForcedVersionThatPassesIsRetestedLikeAnyOther` | version sautée | retestée ; `testLeavesAloneWhatMustNotBeRetested` saute toujours l'échec forcé |
| `apworld-preflight-override.test.ts` | - | verts |

Pour une version déjà coincée en production (comme The Wind Waker HD), cliquer sur « Retirer la dérogation » est
sans risque : un jeu n'est bloqué que sur un échec sans dérogation. Sans clic, le prochain passage du test
tournant la lève.
