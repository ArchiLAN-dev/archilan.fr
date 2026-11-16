# Story 54.3 - Mises à jour des packs

**Status:** ready-for-dev
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

- [ ] **Task 1** (AC 1, 6, 7) - Vérificateur (`versions.json` puis releases), champs et migration.
- [ ] **Task 2** (AC 1) - Message planifié, handler async, gestion du quota.
- [ ] **Task 3** (AC 2, 3, 4, 5) - Commandes de mise à jour et de rollback, rétention post-commit.
- [ ] **Task 4** (AC 9) - Interface admin.
- [ ] **Task 5** (AC 10) - Gates, essai réel sur le pack MM (import de v0.9.5.2 puis mise à jour).
