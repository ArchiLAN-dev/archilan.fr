# Story 38.5: Veille quotidienne des versions

**Status:** ready-for-dev
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-24
**Dépend de :** rien. 38.6 consomme sa sortie.

## Story

En tant qu'admin d'ArchiLAN,
je veux que la dernière version de chaque apworld soit vérifiée tous les jours, et qu'une version ne soit
dite « plus récente » que si elle l'est vraiment,
afin de voir les mises à jour disponibles sans lancer la vérification à la main, et de pouvoir confier
la mise à jour à une machine sans risque de régression.

## Contexte

`app:check-apworld-updates` et le bouton de la page catalogue existent (story 14.5), mais rien ne les
planifie. En local, Crystal Project affichait encore 0.16.0 comme dernière version alors que la 0.17.0
était déployée et la 0.18.2 publiée depuis le 2026-09-11.

La comparaison actuelle ne suffit pas pour décider seule d'une mise à jour :

- c'est une **égalité de chaînes** après `ltrim('vV')` : toute différence vaut « mise à jour
  disponible », y compris une version **plus ancienne** ;
- `ltrim('vV')` ne retire rien d'un tag comme `CrystalProject-v0.17.0` ;
- les **pré-releases** GitHub ne sont pas écartées, seuls les brouillons le sont
  (`ApworldVersionChecker`, `findLatestReleaseWithApworld`) ;
- la même règle est écrite **deux fois**, dans `GameCatalogSync` et dans
  `Domain/ValueObject/ApworldUpdateStatus`, avec un commentaire « Keep the two in lockstep ».

## Critères d'acceptation métier

1. **Tous les jours.** La vérification tourne chaque nuit pour tous les jeux dont la source est un dépôt
   GitHub, sans action humaine. Le bouton manuel reste disponible.
2. **Vraiment plus récente.** Une release n'est « mise à jour disponible » que si sa version est
   strictement supérieure à la version déployée. Une version égale est « à jour ». Une version inférieure
   n'est **jamais** une mise à jour.
3. **Pré-releases écartées.** Une pré-release GitHub n'est jamais proposée comme dernière version.
4. **Versions illisibles.** Si l'une des deux versions ne se lit pas comme un numéro de version, le statut
   est « indéterminé » : affiché aux admins, mais **jamais** utilisé pour une mise à jour automatique.
5. **Limite GitHub.** Si le quota d'API GitHub est atteint, la veille s'arrête proprement et reprend au
   passage suivant, là où elle s'est arrêtée, plutôt que de revérifier toujours les mêmes premiers jeux.

## Critères d'acceptation techniques

6. **Comparaison de versions**, règle pure : `ApworldVersion` (`GameSelection/Domain/ValueObject/`),
   `ApworldVersion::parse(string $tag): ?ApworldVersion` extrait la **dernière** suite `X.Y` ou `X.Y.Z`
   (avec un éventuel suffixe de pré-version) d'un tag quelconque : `v0.18.2`, `0.18.2`,
   `CrystalProject-v0.18.2`, `Crystal Project Version 0.18.2`. `compareTo()` suit l'ordre semver, une
   pré-version étant inférieure à la version finale.
7. **Une seule règle.** `ApworldUpdateStatus::compute()` et `GameCatalogSync` utilisent `ApworldVersion`.
   Le doublon disparaît : `GameCatalogSync` délègue au value object. Nouveau statut
   `Game::UPDATE_STATUS_UNDETERMINED` pour l'AC 4.
8. **Pré-releases.** `findLatestReleaseWithApworld` et `listAssets` écartent `prerelease: true` comme ils
   écartent `draft: true`.
9. **Planification.** `CheckApworldUpdatesMessage` chaque jour à 04:00 (Europe/Paris) dans
   `api/src/Schedule.php`, handler qui appelle `CheckApworldUpdatesService::checkAll()`.
10. **Reprise.** `checkAll()` traite les jeux par `apworld_checked_at` croissant (jamais vérifiés
    d'abord) au lieu de l'ordre alphabétique : un quota atteint laisse de côté les jeux les plus
    récemment vérifiés, pas toujours les derniers de l'alphabet.
11. **Sortie.** `ApworldUpdateCheckReport` gagne la liste des jeux dont le statut est « mise à jour
    disponible » avec leur tag, pour que 38.6 n'ait pas à relire la base.
12. `composer gates` passe.

## Ordre TDD

1. `tests/Unit/GameSelection/ApworldVersionTest.php` :
   - `testParsesAPlainSemver`
   - `testParsesATagWithPrefixAndV` (`CrystalProject-v0.18.2`)
   - `testParsesAReleaseNameWithWords` (`Crystal Project Version 0.18.2`)
   - `testParsesTwoComponentVersions`
   - `testReturnsNullWithoutAnyVersionNumber`
   - `testOrdersNumericallyNotLexically` (`0.10.0 > 0.9.0`)
   - `testPreReleaseIsLowerThanItsFinalVersion`
2. `tests/Unit/GameSelection/ApworldUpdateStatusTest.php` (existant ou nouveau) :
   - `testNewerIsUpdateAvailable`
   - `testEqualIsUpToDate`
   - `testOlderIsNeverAnUpdate`
   - `testUnparsableIsUndetermined`
3. `tests/Unit/CatalogSync/ApworldVersionCheckerTest.php` - `MockHttpClient` :
   - `testSkipsPreReleases`
   - `testSkipsDrafts` (non-régression)
4. `tests/Unit/CatalogSync/CheckApworldUpdatesServiceTest.php` :
   - `testChecksLeastRecentlyCheckedFirst`
   - `testReportListsGamesWithAnUpdateAvailable`
   - `testStopsOnRateLimitAndReportsIt` (non-régression)

## Tasks / Subtasks

- [ ] **Task 1** (AC 6) - `ApworldVersion` et ses tests.
- [ ] **Task 2** (AC 2, 4, 7) - Une seule règle de statut, statut indéterminé, affichage côté admin.
- [ ] **Task 3** (AC 3, 8) - Pré-releases écartées.
- [ ] **Task 4** (AC 5, 10, 11) - Ordre de traitement et rapport enrichi.
- [ ] **Task 5** (AC 1, 9) - Message, handler, planification.
- [ ] **Task 6** (AC 12) - Gates.

## Dev Notes

- **Le statut est aussi calculé en lecture** par la requête catalogue (raison d'être de
  `ApworldUpdateStatus`). Après cette story, les deux chemins passent par `ApworldVersion` : vérifier la
  page catalogue et la page admin du jeu.
- **Frontend.** Le nouveau statut `undetermined` doit être rendu là où `update_available` et
  `up_to_date` le sont (`admin-catalogue-sync-page.tsx`), sinon il s'affichera en brut.
- **Pas de mise à jour ici.** Cette story ne télécharge ni n'importe rien : c'est 38.6.

### References

- [Source: api/src/CatalogSync/Application/Service/ApworldVersionChecker.php]
- [Source: api/src/CatalogSync/Application/Command/CheckApworldUpdatesService.php]
- [Source: api/src/GameSelection/Domain/ValueObject/ApworldUpdateStatus.php]
- [Source: _bmad-output/implementation-artifacts/14-5-apworld-version-checker-service.md]
