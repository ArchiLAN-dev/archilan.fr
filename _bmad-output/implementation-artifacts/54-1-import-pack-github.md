# Story 54.1 - Importer un pack depuis GitHub

**Status:** review
**Epic:** 54 - Trackers de jeu, les packs PopTracker sur le site (`_bmad-output/planning-artifacts/epics/epic-54-trackers-poptracker.md`)
**Date:** 2026-11-16
**Branche:** `feature/epic-54-story-1-import-pack-github`
**Dépend de:** rien.

## Story

En tant qu'admin,
je veux coller le lien GitHub d'un pack PopTracker sur la fiche d'un jeu,
afin que le site le télécharge, le vérifie et le garde, sans manipuler de fichier à la main.

## Contexte

- Jeu : `Game` (`api/src/GameSelection/Domain/Entity/Game.php`, id `string(32)`). Éditeur admin :
  `/admin/jeux/[gameId]` → `features/admin/admin-game-editor`.
- GitHub : `ApworldVersionChecker` (`api/src/CatalogSync/Application/Service/ApworldVersionChecker.php`) montre déjà
  la lecture des releases (`parseSourceUrl`, `githubHeaders`, `downloadAsset` avec le jeton) et
  `GithubRateLimitException`. `string $githubToken` est lié dans `config/services.yaml`. **Ne pas réutiliser la classe**
  (elle est dédiée aux apworlds) : reprendre le motif dans un client propre au contexte `Trackers`.
- Stockage : `S3MinioStorage` (`api/src/Shared/Infrastructure/Adapter/S3MinioStorage.php`).
- Format d'un pack et exemple MM : voir l'epic. Point d'attention : la release MM v0.9.5.3 n'a **pas d'asset**, il faut
  prendre le zipball du tag, dont le contenu est sous `Majoras-Mask-AP-PopTracker-Pack-0.9.5.3/`. Son `manifest.json`
  commence par un **BOM**.
- Migration : prendre le numéro qui suit la dernière migration présente sur `develop` au moment du commit.

## Décisions

1. **Nouveau contexte `api/src/Trackers/`** (Domain / Application / Infrastructure / Presentation). Il lit l'existence
   d'un jeu par `GameExistsQueryInterface`, déclarée dans `Trackers/Application/Query/` et implémentée dans
   `GameSelection`. Vérifier que `app:architecture:ddd` couvre le nouveau contexte (sinon l'y déclarer).
2. **Modèle :**
   - `TrackerPack` : `id` (uuid), `gameId`, `sourceUrl` (dépôt normalisé `https://github.com/{owner}/{repo}`, ou URL
     du zip), `packageUid`, `name`, `author`, `gameName` (celui du manifest), `licenseSpdx` (nullable), `versionsUrl`
     (nullable), `currentVersionId`, `createdAt`, `createdBy`.
   - `TrackerPackVersion` : `id`, `packId`, `packageVersion`, `gitTag` (nullable), `downloadUrl`, `sha256`,
     `storageKey`, `rootPath` (`""` si `manifest.json` est à la racine du zip), `sizeBytes`, `variants` (json :
     `[{uid, displayName, flags[]}]`), `minPopTrackerVersion`, `targetPopTrackerVersion` (nullable), `changelog`
     (nullable), `importedAt`.
   - Unicité `(gameId, packageUid)`.
3. **Liens acceptés** (`TrackerPackSource`, objet valeur pur, tests unitaires) :
   - `https://github.com/{o}/{r}/releases/tag/{tag}` → cette release ;
   - `https://github.com/{o}/{r}` ou `.../releases` ou `.../releases/latest` → dernière release publiée (hors
     prerelease et brouillon) ;
   - `https://github.com/{o}/{r}/archive/refs/tags/{tag}.zip` ou `https://github.com/{o}/{r}/releases/download/...zip`
     → ce zip.
   - Tout autre lien : `tracker_pack_url_invalid`. **Uniquement github.com et codeload / objects.githubusercontent**
     (redirections suivies dans cette liste seulement), pour éviter les requêtes vers n'importe quel hôte.
4. **Choix du zip dans une release** : un seul asset `.zip` → celui-ci. Plusieurs → celui dont le nom contient
   « poptracker » ou « pack », sinon le premier. Aucun → `zipball_url` du tag.
5. **Téléchargement synchrone** dans la requête admin, délai 60 s, jeton GitHub si configuré (sans jeton : on tente
   en anonyme, et un 403 ou 429 de quota devient `github_rate_limited`, réponse 503).
6. **Validation** (`TrackerPackArchiveInspector`, Infrastructure, sur `ZipArchive`) :
   - zip ≤ 50 Mo, décompressé ≤ 200 Mo (somme des tailles déclarées **et** contrôle pendant la lecture), ≤ 5 000
     fichiers → sinon `tracker_pack_too_large` ;
   - aucun chemin absolu, `..`, antislash ni octet nul → `tracker_pack_unsafe_path` ;
   - `rootPath` = dossier du `manifest.json` le moins profond (profondeur ≤ 1) → sinon `tracker_pack_manifest_missing` ;
   - manifest lu en **JSON tolérant** : BOM retiré, commentaires `//` et `/* */` hors chaînes, virgules finales
     (`TolerantJson`, testé unitairement) → sinon `tracker_pack_manifest_invalid` ;
   - champs obligatoires : `package_uid`, `name`, `variants` non vide (les packs sans variantes reçoivent une variante
     implicite `""`, nommée « Standard », comme PopTracker) ;
   - aucun fichier extrait sur disque : on lit le manifest en mémoire, l'archive est stockée telle quelle.
7. **Pas de variante `ap`** : import accepté, la réponse et l'admin le signalent (« pas d'autotracking Archipelago »).
8. **Déjà importé** (`gameId` et `packageUid` déjà présents) : `tracker_pack_already_imported` (409). La mise à jour
   arrive en 54.3.
9. **Licence** : `GET /repos/{o}/{r}` → `license.spdx_id` (`NOASSERTION` = null), sinon rien. Pas d'analyse du fichier
   dans cette story.
10. **Stockage** : bucket des apworlds (`$minioApworldsBucket`), préfixe `tracker-packs/{packId}/{versionId}.zip`. Pas
    de nouveau bucket, donc pas de changement d'ops.
11. **Endpoints** (admin uniquement) :
    - `POST /api/v1/admin/games/{gameId}/tracker-packs` `{url}` → 201 `{data: TrackerPackAdminView}` ;
    - `GET /api/v1/admin/games/{gameId}/tracker-packs` → `{data: TrackerPackAdminView[]}`.
    - `TrackerPackAdminView` : id, name, author, packageUid, gameName, sourceUrl, licenseSpdx, hasApVariant, version
      courante (packageVersion, gitTag, sizeBytes, variants, minPopTrackerVersion, importedAt).
12. **Erreurs** : 422 avec les codes ci-dessus, 404 jeu inconnu, 503 `github_rate_limited` ou
    `tracker_pack_download_failed` (réseau, 5xx GitHub). Log PSR-3 de chaque échec, avec l'URL et le code.
13. **Front admin** : section « Trackers » dans `admin-game-editor` : champ de lien, bouton « Importer », état de
    chargement (le téléchargement peut prendre quelques secondes), message d'erreur traduit pour chaque code, liste
    des packs importés (nom, version, auteur, variantes, licence, badge « Archipelago » ou « Sans autotracking »).

## Critères d'acceptation

1. L'admin colle `https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack/releases/tag/v0.9.5.3` sur un jeu : le
   pack « Majora's Mask: Recompiled » v0.9.5.3 apparaît, avec 3 variantes, le badge Archipelago et la licence MIT.
   En base, `rootPath` vaut `Majoras-Mask-AP-PopTracker-Pack-0.9.5.3`.
2. Le lien du dépôt seul importe la dernière release.
3. Un second import du même pack sur le même jeu est refusé avec un message qui renvoie vers la mise à jour.
4. Un lien hors GitHub, un zip sans manifest, un zip trop gros ou contenant `../` sont refusés avec un message clair,
   sans rien écrire en base ni dans MinIO.
5. Un membre non admin reçoit 403 sur les deux endpoints.

## Tests et gates

6. Unitaires : `TrackerPackSource` (chaque forme de lien, liens refusés), `TolerantJson` (BOM, commentaires, virgules
   finales, commentaire dans une chaîne conservé), choix de l'asset, garde-fous de l'inspecteur sur des zips
   fabriqués dans le test (chemin `..`, absolu, trop de fichiers, manifest en profondeur 2).
7. Fonctionnels : import nominal avec un client HTTP simulé (`MockHttpClient`) et un zip de test, doublon, 403, 404,
   quota GitHub.
8. Front : section Trackers (import, erreurs, liste).
9. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 6) - Objets valeur `TrackerPackSource`, `TolerantJson`, inspecteur d'archive.
- [x] **Task 2** (AC 1, 2, 4) - Client GitHub du contexte, choix de l'asset, téléchargement borné.
- [x] **Task 3** (AC 1, 3) - Entités, migration réversible, repository, commande `ImportTrackerPack`, stockage MinIO.
- [x] **Task 4** (AC 1, 5) - Contrôleur admin, vues, codes d'erreur.
- [x] **Task 5** (AC 8) - Section « Trackers » de l'éditeur de jeu.
- [x] **Task 6** (AC 9) - Gates, import réel du pack MM en local.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- Nouveau contexte `Trackers` : déclaré dans `DddArchitectureValidator::CONTEXTS`, `api/CLAUDE.md`, l'exclusion du
  Domain dans `services.yaml` et le mapping Doctrine.
- **Écart avec la décision 1** : le jeu est lu par un port `Trackers/Application/Port/TrackerGameInterface`, implémenté
  dans `Trackers/Infrastructure/Adapter/RepositoryTrackerGame` sur `GameRepositoryInterface`, comme `Notes` le fait
  pour les inscriptions. Pas de nouvelle interface côté `GameSelection`.
- Identifiants : `string(32)` comme le reste du schéma (pas d'uuid).
- Domaine : `TrackerPackSource` (formes de liens, github.com seulement, ni identifiants ni port), `TolerantJson` (BOM,
  commentaires, virgules finales, hors chaînes), `TrackerPackManifest` (variante implicite « Standard » sans
  `variants`, `versions_url` gardé seulement en https), `TrackerPack` et `TrackerPackVersion` (limites 50 Mo, 200 Mo
  décompressé, 5 000 fichiers).
- `GithubTrackerPackFetcher` : redirections suivies à la main sur une liste d'hôtes GitHub, jeton envoyé à
  `api.github.com` seulement (les URL signées des assets refusent un en-tête d'authentification), téléchargement en
  flux coupé au-delà de 50 Mo, quota GitHub (403 avec `x-ratelimit-remaining: 0`, ou 429) en 503
  `github_rate_limited`. Sans asset zip, on prend `archive/refs/tags/{tag}.zip` et pas le `zipball` de l'API : c'est
  ce lien qui donne le dossier racine `{repo}-{version}` et que les `versions.json` des packs citent.
- `ZipTrackerPackArchiveInspector` : lecture du répertoire central seulement, rien n'est extrait, manifest lu avec un
  plafond de 1 Mo.
- Ordre d'écriture : objet MinIO d'abord, ligne ensuite. Un échec en base laisse au pire un objet orphelin, jamais
  une ligne sans fichier.
- Les messages d'erreur sont en français côté API, et le front les affiche tels quels.
- Front : onglet « Trackers » dans l'éditeur de jeu (`admin-game-tracker-packs.tsx`, `admin-tracker-packs-api.ts`).
- **Essai réel** (2026-11-16) avec les vraies classes `GithubTrackerPackFetcher`, `ZipTrackerPackArchiveInspector` et
  `TrackerPackManifest` sur GitHub : `.../releases/tag/v0.9.5.3` et le lien du dépôt donnent tous les deux
  `archive/refs/tags/v0.9.5.3.zip` (7,2 Mo), racine `Majoras-Mask-AP-PopTracker-Pack-0.9.5.3`, licence MIT,
  `MM_AP` v0.9.5.3, variantes `standard`, `itemsonly`, `mapsonly`, flag `ap`.

- Gates : `composer gates:fast` vert (PHPStan, cs-fixer, DDD, Rector, 3 955 tests dont les 7 fonctionnels de la
  story sur Postgres local). `pnpm gates` : typecheck, lint et 1 675 tests verts. `next build` s'est arrêté une fois
  sur un avertissement de sécurité de Next 16.4.3 (vulnérabilité connue de cette version), puis est passé au second
  essai. Montée de version de Next à traiter à part.

### File List

- `api/CLAUDE.md` (modifié)
- `api/config/packages/doctrine.yaml` (modifié)
- `api/config/services.yaml` (modifié)
- `api/migrations/Version20261116100000.php` (nouveau)
- `api/src/Shared/Application/Support/DddArchitectureValidator.php` (modifié)
- `api/src/Trackers/Domain/Entity/TrackerPack.php` (nouveau)
- `api/src/Trackers/Domain/Entity/TrackerPackVersion.php` (nouveau)
- `api/src/Trackers/Domain/Enum/TrackerPackSourceKind.php` (nouveau)
- `api/src/Trackers/Domain/Repository/TrackerPackRepositoryInterface.php` (nouveau)
- `api/src/Trackers/Domain/Service/TolerantJson.php` (nouveau)
- `api/src/Trackers/Domain/ValueObject/TrackerPackManifest.php` (nouveau)
- `api/src/Trackers/Domain/ValueObject/TrackerPackSource.php` (nouveau)
- `api/src/Trackers/Domain/ValueObject/TrackerPackVariant.php` (nouveau)
- `api/src/Trackers/Application/Command/ImportTrackerPack.php` (nouveau)
- `api/src/Trackers/Application/Command/ImportedTrackerPack.php` (nouveau)
- `api/src/Trackers/Application/Port/TrackerGameInterface.php` (nouveau)
- `api/src/Trackers/Application/Port/TrackerPackArchiveInspectorInterface.php` (nouveau)
- `api/src/Trackers/Application/Port/TrackerPackFetcherInterface.php` (nouveau)
- `api/src/Trackers/Application/Query/AdminTrackerPackQuery.php` (nouveau)
- `api/src/Trackers/Application/Query/AdminTrackerPackQueryInterface.php` (nouveau)
- `api/src/Trackers/Application/Query/AdminTrackerPackView.php` (nouveau)
- `api/src/Trackers/Application/Support/FetchedTrackerPack.php` (nouveau)
- `api/src/Trackers/Application/Support/InspectedTrackerPackArchive.php` (nouveau)
- `api/src/Trackers/Infrastructure/Adapter/RepositoryTrackerGame.php` (nouveau)
- `api/src/Trackers/Infrastructure/Adapter/ZipTrackerPackArchiveInspector.php` (nouveau)
- `api/src/Trackers/Infrastructure/Dbal/DbalAdminTrackerPackQuery.php` (nouveau)
- `api/src/Trackers/Infrastructure/Doctrine/DoctrineTrackerPackRepository.php` (nouveau)
- `api/src/Trackers/Infrastructure/Http/GithubTrackerPackFetcher.php` (nouveau)
- `api/src/Trackers/Presentation/Controller/Admin/AdminTrackerPackController.php` (nouveau)
- `api/tests/Fixtures/Trackers/TrackerPackZip.php` (nouveau)
- `api/tests/Functional/AdminTrackerPackTest.php` (nouveau)
- `api/tests/Unit/Trackers/GithubTrackerPackFetcherTest.php` (nouveau)
- `api/tests/Unit/Trackers/TolerantJsonTest.php` (nouveau)
- `api/tests/Unit/Trackers/TrackerPackManifestTest.php` (nouveau)
- `api/tests/Unit/Trackers/TrackerPackSourceTest.php` (nouveau)
- `api/tests/Unit/Trackers/ZipTrackerPackArchiveInspectorTest.php` (nouveau)
- `api/tests/Unit/DddArchitectureValidatorTest.php` (modifié : contexte `Trackers` dans l'arborescence factice)
- `frontend/src/features/admin/admin-game-editor.tsx` (modifié)
- `frontend/src/features/admin/admin-game-tracker-packs.tsx` (nouveau)
- `frontend/src/features/admin/admin-game-tracker-packs.test.tsx` (nouveau)
- `frontend/src/features/admin/admin-tracker-packs-api.ts` (nouveau)
- `frontend/src/features/admin/admin-tracker-packs-api.test.ts` (nouveau)
