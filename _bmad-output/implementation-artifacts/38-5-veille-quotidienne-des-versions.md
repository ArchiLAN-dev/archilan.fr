# Story 38.5: Veille quotidienne des versions

**Status:** review
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
- la même règle est écrite **deux fois**. *Corrigé à l'implémentation :* `GameCatalogSync` délègue déjà
  à `Domain/ValueObject/ApworldUpdateStatus` ; la seconde copie est dans
  `ApworldVersionChecker::check()`, qui refaisait sa propre égalité de chaînes.

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
7. **Une seule règle.** `ApworldUpdateStatus::compute()` utilise `ApworldVersion`, et
   `ApworldVersionChecker::check()` ne compare plus rien lui-même : il demande le statut au jeu
   (`Game::computeApworldUpdateStatus()`). Nouveau statut `Game::UPDATE_STATUS_UNDETERMINED` pour l'AC 4.
8. **Pré-releases.** `findLatestReleaseWithApworld` et `listAssets` écartent `prerelease: true` comme ils
   écartent `draft: true`.
9. **Planification.** `CheckApworldUpdatesMessage` chaque jour à 04:00 (Europe/Paris) dans
   `api/src/Schedule.php`, handler qui appelle `CheckApworldUpdatesService::checkAll()`.
10. **Reprise.** `checkAll()` traite les jeux par `apworld_checked_at` croissant (jamais vérifiés
    d'abord) au lieu de l'ordre alphabétique : un quota atteint laisse de côté les jeux les plus
    récemment vérifiés, pas toujours les derniers de l'alphabet.
11. **Sortie.** `ApworldUpdateCheckReport` gagne la liste des jeux dont le statut est « mise à jour
    disponible » avec leur tag, pour que 38.6 n'ait pas à relire la base.
11 bis. **Résilience** (ajoutée après le premier passage réel). Une erreur réseau ou une réponse
    illisible sur un dépôt est journalisée (`catalog_sync.apworld_check_failed`), comptée dans le rapport
    (`failed`), et la veille continue. Avant, une connexion coupée arrêtait tout le passage, avant même
    l'enregistrement final : tout ce qui avait été vérifié était perdu.
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

- [x] **Task 1** (AC 6) - `ApworldVersion` et ses tests.
- [x] **Task 2** (AC 2, 4, 7) - Une seule règle de statut, statut indéterminé, affichage côté admin.
- [x] **Task 3** (AC 3, 8) - Pré-releases écartées.
- [x] **Task 4** (AC 5, 10, 11) - Ordre de traitement et rapport enrichi.
- [x] **Task 5** (AC 1, 9) - Message, handler, planification.
- [x] **Task 6** (AC 12) - Gates.

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

## Dev Agent Record

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `ApworldVersion` | 14 échecs sur 15 | 15 tests |
| `ApworldUpdateStatus` (cas ajoutés) | version plus ancienne, même version écrite autrement, statut `undetermined` absent | 10 tests |
| `ApworldVersionChecker` | pré-releases retenues, version plus ancienne vue comme une mise à jour | 15 tests |
| `CheckApworldUpdatesService` | ordre alphabétique, rapport sans les mises à jour | 3 tests |
| Planification (`ScheduleTest`, nouveau) | « n'est pas planifié » | vert |
| Résilience réseau (après le passage réel) | `TransportException: Connection was reset` | 4 tests |
| Frontend `apworld-update-status` | 4 échecs sur 4 | 4 tests |

### Vérifications

- `composer gates` : vert, 1976 tests. `pnpm gates` : vert, 486 tests, build OK (les 10 avertissements de
  lint sont ceux de `develop`).
- **Passage réel contre GitHub**, sur une copie de la base locale : le premier essai s'est arrêté net sur
  une connexion coupée (dépôt HeroCore), ce qui a donné l'AC 11 bis. Après correction : **517 apworlds
  vérifiés, 233 mises à jour disponibles**. Échantillon contrôlé en base, toutes justes (2048 1.1.2 vers
  1.1.3, FFT PSX 0.3.1 vers 0.4.0, Psychonauts 0.9.3-BETA vers 1.0.0…).
- Les deux pièges de l'ancienne règle, sur les vraies données :
  - Crystal Project : la veille voit enfin la 0.18.2, déployée, donc « à jour » ;
  - Pokémon Crystal : `6.0.0-beta.1` déployée, dernière finale `5.4.6` (pré-releases écartées). Statut
    « à jour » : aucune rétrogradation proposée.

### Écarts et suites

- Le doublon de la règle était dans le checker, pas dans `GameCatalogSync` (Contexte et AC 7 corrigés).
- Frontend : libellés et tons des statuts centralisés dans `apworld-update-status.ts`, utilisé par la page
  catalogue et l'éditeur de jeu. Les deux écrans divergeaient pour `unknown` (« Non vérifié » contre
  « Version inconnue ») : unifié sur « Non vérifié ». Un statut inattendu n'est plus affiché comme « Non
  suivi ».
- **Pour la 38.6 :** 233 mises à jour en attente, parce que la veille n'avait pas tourné depuis mai. Sans
  plafond, la première nuit de mise à jour automatique enverrait 233 candidats au test d'un coup. Noté dans
  la story 38.6.

## Corrections de revue (2026-09-26)

- **La reprise suit la dernière vérification, pas la date de release.** `apworld_checked_at` contenait la date
  de publication de la release : trier dessus relançait chaque nuit les mêmes jeux à release ancienne.
  Nouvelle colonne `apworld_last_checked_at` (`Version20260926100000`), posée par la veille sur chaque jeu
  tenté, même en échec, pour qu'un dépôt en panne ne bloque pas la tête de file.
- **Le jeu qui épuise le quota GitHub figure au rapport.** Sa release était déjà enregistrée ;
  `GithubRateLimitException` porte maintenant ce contrôle (`completedCheck`).
- **`ApworldVersion`** lit autant de composants que l'auteur en écrit (`0.5.1.3` > `0.5.1.2`). Un suffixe
  n'est une pré-release que s'il en nomme une (`alpha`, `beta`, `rc`, `pre`, `dev`, `preview`) ; tout autre
  suffixe (`-fix`, `-1`) est un correctif, classé après la version (décision du 2026-09-26).
- **La veille tourne sur le worker `async`**, plus dans le scheduler, qu'elle bloquait pendant tout son passage.
