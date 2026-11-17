# Story 54.3 - Mises à jour des packs

**Status:** review
**Epic:** 54 - Trackers de jeu, les packs PopTracker sur le site (`_bmad-output/planning-artifacts/epics/epic-54-trackers-poptracker.md`)
**Date:** 2026-11-16
**Branche:** `feature/epic-54-story-3-mises-a-jour-des-packs`
**Dépend de:** 54.1, 54.2.

## Story

En tant qu'admin,
je veux voir quand un pack a une nouvelle version, lire ce qui change et l'appliquer en un clic,
afin que les joueurs aient un tracker à jour sans que je surveille chaque dépôt.

## Contexte

- Le manifest peut porter un `versions_url` vers un `versions.json` :
  `{"versions": [{"package_version", "download_url", "sha256", "changelog": [..]}]}`.
- Ce fichier n'est pas fiable : dans le tag v0.9.5.3 de MM, il annonce 0.9.5.2 comme dernière version (il est lu sur
  la branche `cube`, pas dans le tag). Les releases GitHub servent de source de secours.
- Le motif de vérification planifiée existe : `CheckApworldUpdatesMessage` dans `api/src/Schedule.php`, traité sur le
  worker async, avec `GithubRateLimitException`.
- Import et validation : 54.1 (même chemin de code, à réutiliser).

## Décisions

1. **Vérification** (`CheckTrackerPackUpdates`), pour chaque pack actif :
   1. si `versionsUrl` : lire le fichier (JSON tolérant, ≤ 1 Mo, hôtes GitHub seulement, comme 54.1), prendre la
      version la plus haute ;
   2. sinon, ou si le fichier échoue : dernière release GitHub du dépôt source (choix de l'asset de 54.1) ;
   3. comparaison avec `version_compare` après retrait d'un `v` initial. Si la version distante est plus haute, elle
      est enregistrée sur le pack : `availableUpdate` (json : `packageVersion`, `downloadUrl`, `sha256` nullable,
      `changelog` (liste de lignes, ou corps de la release tronqué à 5 000 caractères), `gitTag`, `checkedAt`).
      Sinon `availableUpdate` est remis à nul.
   - `lastCheckedAt` et `lastCheckError` (code court) sont gardés sur le pack.
2. **Planification** : une fois par jour (`RecurringMessage::cron('40 4 * * *', new CheckTrackerPackUpdatesMessage())`),
   sur le transport async. Un quota GitHub atteint arrête le passage proprement (log, reprise le lendemain).
3. **Pas de mise à jour automatique** : un pack exécute du code chez les joueurs, un admin doit valider.
4. **Mettre à jour** (`UpdateTrackerPack`) :
   - télécharge `downloadUrl`, applique **toutes** les validations de 54.1 ;
   - `sha256` connu et différent → `tracker_pack_checksum_mismatch` (422), rien n'est écrit ;
   - `package_uid` différent de celui du pack → `tracker_pack_uid_mismatch` ;
   - crée une nouvelle `TrackerPackVersion`, qui devient `currentVersionId`, puis vide `availableUpdate` ;
   - si `defaultVariantUid` disparaît de la nouvelle version, il est remis à nul (retour à la première variante) et
     l'admin en est averti dans la réponse.
5. **Revenir en arrière** : `POST /api/v1/admin/tracker-packs/{packId}/rollback` `{versionId}`, vers une version
   conservée.
6. **Rétention** : les 3 dernières versions sont gardées (courante comprise). Au-delà, la ligne et l'objet MinIO sont
   supprimés, **après** le commit (suppression MinIO en effet de bord post-transaction).
7. **Endpoints admin** :
   - `POST /api/v1/admin/tracker-packs/{packId}/check-update` → vérification immédiate de ce pack ;
   - `POST /api/v1/admin/tracker-packs/{packId}/update` → applique `availableUpdate` ;
   - `POST /api/v1/admin/tracker-packs/{packId}/rollback` ;
   - `TrackerPackAdminView` gagne `availableUpdate`, `lastCheckedAt`, `lastCheckError`, `versions` (les conservées).
8. **Mettre à jour depuis un lien** : coller un lien sur un pack existant (même formes qu'en 54.1) applique cette
   version au pack, pour les dépôts sans release propre.
9. **Front admin** : badge « v0.9.5.4 disponible », changelog dépliable, boutons « Mettre à jour », « Vérifier
   maintenant », liste des versions gardées avec « Revenir à cette version », date de la dernière vérification et
   erreur éventuelle.
10. **Parties en cours** : pas d'épinglage. Le tracker charge la version courante à l'ouverture (cf. epic, point ouvert
    1).

## Critères d'acceptation

1. Un pack dont le dépôt publie une version plus récente affiche le badge et le changelog après la vérification
   planifiée ou manuelle.
2. « Mettre à jour » installe la nouvelle version, qui devient celle servie aux joueurs.
3. Un `sha256` incorrect ou un `package_uid` différent bloquent la mise à jour avec un message, et la version courante
   ne change pas.
4. « Revenir à cette version » remet une version conservée en service.
5. Au-delà de 3 versions, la plus ancienne disparaît de la base et de MinIO.
6. Un `versions.json` en retard (cas MM) ne masque pas une release GitHub plus récente.

## Tests et gates

7. Unitaires : comparaison de versions (`v` initial, 4 segments), choix entre `versions.json` et release, rétention.
8. Fonctionnels (`MockHttpClient`) : vérification avec et sans mise à jour, mise à jour nominale, checksum faux, uid
   différent, rollback, quota GitHub pendant le passage planifié.
9. Front : badge, changelog, boutons, liste des versions.
10. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 6, 7) - Vérificateur (`versions.json` puis releases), champs et migration.
- [x] **Task 2** (AC 1) - Message planifié, handler async, gestion du quota.
- [x] **Task 3** (AC 2, 3, 4, 5) - Commandes de mise à jour et de rollback, rétention post-commit.
- [x] **Task 4** (AC 9) - Interface admin.
- [x] **Task 5** (AC 10) - Gates, essai réel sur le pack MM (import de v0.9.5.2 puis mise à jour).

## Dev Agent Record

**Date:** 2026-11-17

### Écarts et décisions

- **Noms** : `UpdateTrackerPack` existait déjà (PATCH de la 54.2). La commande d'installation s'appelle
  `UpgradeTrackerPack` ; route `POST .../update` inchangée. `CheckTrackerPackUpdates` (`checkOne`, `checkAll`),
  `RollbackTrackerPack`, résultat commun `ServedTrackerPackVersion {versionId, packageVersion, defaultVariantReset}`.
- **Les deux sources sont toujours lues** (pas « release seulement si le fichier échoue ») : la plus haute version des
  deux gagne. C'est ce qu'exige l'AC 6 (MM : `versions.json` en 0.9.5.2, release en 0.9.5.3).
- Échec d'une seule des deux sources : la vérification compte comme réussie avec l'autre. `lastCheckError` n'est posé
  que si aucune source n'a répondu.
- Port MinIO : ajout de `delete()` (S3, double de test, espion des tests unitaires). Suppression des zips expirés
  après le flush, un échec est seulement journalisé.
- Une mise à jour reste proposée après un retour en arrière (`serve()` ne vide `availableUpdate` que pour une version
  au moins égale).
- **Zip déjà gardé** (trouvé à l'essai réel) : installer un zip identique à une version conservée remet cette version
  en service au lieu d'en créer une copie ; si c'est déjà la version servie, 409
  `tracker_pack_version_already_served`.
- Erreur supplémentaire : `tracker_pack_no_update` (422) quand on met à jour sans mise à jour connue.
- Planification : `40 4 * * *` Europe/Paris, message `CheckTrackerPackUpdatesMessage` routé sur `async`.

### Fichiers

- API : `TrackerPackUpdate` (VO), `TrackerPackVersions` (comparaison, choix, `versions.json`, rétention),
  `TrackerPack` (`recordCheck`, `recordCheckFailure`, `serve`), getters de `TrackerPackVersion`, repository
  (`findEnabled`, `findVersions`, `save` avec versions expirées), port `TrackerPackUpdateSourceInterface` implémenté
  par `GithubTrackerPackFetcher` (+ `fetchZip`), commandes, message et handler, `Schedule.php`, `messenger.yaml`,
  vue et requête admin, 3 routes admin, migration `Version20261117110000`.
- Tests : `TrackerPackVersionsTest` (7), `TrackerPackTest` (5), 4 tests de plus dans `GithubTrackerPackFetcherTest`,
  `tests/Functional/TrackerPackUpdatesTest.php` (10).
- Front : `admin-tracker-packs-api.ts` (types, `checkTrackerPackUpdate`, `upgradeTrackerPack`, `rollbackTrackerPack`),
  `admin-tracker-pack-updates.tsx` (badge, changelog dépliable, boutons, lien, versions gardées), et leurs tests.

### Gates

- `./scripts/gates.sh --api` vert. Front : typecheck, lint, 1697 tests verts, build vert au second lancement
  (avertissement de sécurité de Next.js 16.4.3, corrigé par le hotfix #1105).
- Deux échecs isolés pendant les runs, sans lien : `YamlTemplateTest` instable sous ParaTest (vert seul 3 fois), puis
  épuisement des ports TCP locaux de Windows après des runs enchaînés. Le cache `var/cache/test` périmé a aussi dû
  être vidé une fois (nouvelle colonne absente du schéma de test).

### Essai réel (2026-11-17, local, vrai GitHub)

- Pack MM : installation de v0.9.5.2 par lien, puis « Vérifier » trouve 0.9.5.3 par la release alors que le
  `versions.json` annonce 0.9.5.2, avec le vrai changelog. « Mettre à jour » sert 0.9.5.3.

