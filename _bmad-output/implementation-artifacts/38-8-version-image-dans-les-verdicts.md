# Story 38.8: Version de l'image dans les verdicts

**Status:** ready-for-dev
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-24
**Dépend de :** rien. Prérequis de 38.9.
**Dépôts :** `orchestrateur`, `archilan-orchestrateur-client` (`packages/orchestrateur-client`), monorepo.

## Story

En tant qu'admin d'ArchiLAN,
je veux savoir sur quelle image Archipelago chaque apworld a été testé, et quelle image tourne en ce
moment,
afin de repérer les jeux qui n'ont pas encore été vérifiés sur l'image actuelle, et de distinguer une
régression de notre image d'un apworld cassé.

## Contexte

Un verdict de test (`ApworldPreflight` : `status`, `error`, `checkedAt`, `overridden`) ne dit pas **sur
quelle image** il a été obtenu. Sans cette information, le test tournant de 38.9 ne peut pas donner la
priorité aux jeux testés sur une ancienne image, et un incident ne peut pas dire « ça passait sur la
0.16.0, ça casse sur la 0.16.1 ».

Le 2026-09-24, la trace d'un échec en prod pointait la ligne 468 de `generate_multiworld.py`, ce qui a
révélé que la prod tournait sur l'image v0.16.0 alors que la v0.16.1 était taguée depuis le 2026-08-28.
Rien dans l'interface ne le montrait.

En prod, `AP_IMAGE` est déjà une référence versionnée (`ghcr.io/archilan-dev/archipelago:0.x.y`). En
local, c'est `archipelago:latest`, qui ne dit rien. L'**identifiant** de l'image (`docker inspect`, le
`sha256:` de l'image) couvre les deux cas, et détecte aussi un re-push sur un même tag.

## Critères d'acceptation métier

1. **Verdict daté par l'image.** Chaque nouveau verdict de test d'apworld indique la référence et
   l'identifiant de l'image qui l'a produit.
2. **Image courante.** L'API connaît la référence et l'identifiant de l'image que l'orchestrateur utilise
   en ce moment.
3. **Affichage.** La page admin d'un jeu affiche « testé sur {référence} » à côté du verdict, et signale
   quand cette image n'est plus l'image courante.
4. **Anciens verdicts.** Un verdict antérieur à cette story n'a pas d'image : il est affiché « image
   inconnue » et compte comme testé sur une ancienne image pour 38.9.

## Critères d'acceptation techniques

### Orchestrateur

5. `storage.ApworldPreflight` gagne `Image` (`json:"image,omitempty"`) et `ImageID`
   (`json:"imageId,omitempty"`), renseignés par `RunApworldPreflight` à chaque verdict **terminé**
   (`passed`, `failed`, `skipped`). Un sidecar existant sans ces champs se relit sans erreur.
6. `docker.Client` expose `ImageID(ctx, ref) (string, error)` via `GET /images/{ref}/json`. Le résultat
   est mis en cache pour la durée du process, avec invalidation si la référence change ; un échec
   d'inspection n'empêche pas le verdict d'être enregistré (champ vide, `warn` dans les logs).
7. Nouvel endpoint authentifié `GET /runtime` : `{"apImage": "...", "apImageId": "sha256:..."}`.
   Documenté dans le Swagger comme les autres routes.
8. Tests Go d'abord : `apworld_preflight_test.go` (le verdict porte l'image), test du handler `/runtime`,
   test de relecture d'un sidecar ancien.

### Client PHP (`archilan/orchestrateur-client`)

9. `ApworldPreflight` gagne `?string $image` et `?string $imageId`, lus s'ils sont présents.
10. `OrchestratorClient::runtime()` renvoie un `RuntimeInfo` (`apImage`, `apImageId`).
11. Tests PHPUnit du package d'abord, puis tag **`v1.10.0`** (ajout rétrocompatible).

### API

12. `composer.json` : contrainte `archilan/orchestrateur-client` relevée à `>=1.10.0`.
13. `RunnerGatewayInterface::fetchApworldPreflights()` ajoute `image` et `imageId` au payload de chaque
    verdict ; nouvelle méthode `fetchRuntime(): ?array{apImage: string, apImageId: string}` (`null` si
    l'orchestrateur ne répond pas). `NullRunnerGateway` suit.
14. `AdminGameLibrary::detail()` expose l'image du verdict et un booléen « testé sur l'image courante ».
15. Frontend : affichage dans `admin-game-editor.tsx`, à côté du verdict existant.
16. `composer gates` et `pnpm gates` passent, et `go test ./...` dans l'orchestrateur.

## Ordre TDD

1. **Orchestrateur** (Go, `go test`) :
   - `TestRunApworldPreflightRecordsTheImage`
   - `TestPreflightStillRecordedWhenImageInspectionFails`
   - `TestLegacySidecarWithoutImageIsReadable`
   - `TestRuntimeHandlerReturnsImageAndId`
2. **Client PHP** :
   - `ApworldPreflightTest::testReadsImageAndImageIdWhenPresent`
   - `ApworldPreflightTest::testImageIsNullForALegacyVerdict`
   - `OrchestratorClientRuntimeTest::testRuntimeParsesTheResponse`
3. **API** :
   - `RunnerGatewayTest::testPreflightPayloadCarriesTheImage`
   - `RunnerGatewayTest::testFetchRuntimeReturnsNullWhenTheRunnerIsDown`
   - `AdminGameLibraryTest::testDetailFlagsAVerdictFromAnOlderImage`

## Tasks / Subtasks

- [ ] **Task 1** (AC 5-8) - Orchestrateur : image dans le verdict, `ImageID`, `/runtime`. Branche et PR dans
  le dépôt `orchestrateur`, image publiée.
- [ ] **Task 2** (AC 9-11) - Client PHP, tag `v1.10.0`.
- [ ] **Task 3** (AC 12-14) - API.
- [ ] **Task 4** (AC 15) - Frontend.
- [ ] **Task 5** (AC 16) - Gates des trois dépôts.

## Dev Notes

- **Ordre de déploiement.** Orchestrateur d'abord (champs ajoutés, rien de retiré), puis client, puis API.
  Chaque étape reste compatible avec la précédente : l'API lit des champs optionnels.
- **Trois dépôts, trois PR.** Les dépôts `orchestrateur` et `archilan-orchestrateur-client` sont hors du
  monorepo (voir la mémoire projet sur la topologie). La PR du monorepo cite les deux autres.
- **Pas de changement d'image Archipelago.** L'image n'a pas à connaître sa propre version : c'est
  l'orchestrateur qui la lance qui la connaît.

### References

- [Source: orchestrateur/internal/storage/client.go] - `ApworldPreflight`, `ApworldMeta`
- [Source: orchestrateur/internal/service/apworld_preflight.go] - `RunApworldPreflight`
- [Source: orchestrateur/internal/config/config.go] - `APImage`
- [Source: packages/orchestrateur-client/src/Apworlds/Response/ApworldPreflight.php]
- [Source: .env.prod.example] - `AP_IMAGE` versionnée en prod
