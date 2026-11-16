# Story 54.2 - Les packs d'un jeu

**Status:** review
**Epic:** 54 - Trackers de jeu, les packs PopTracker sur le site (`_bmad-output/planning-artifacts/epics/epic-54-trackers-poptracker.md`)
**Date:** 2026-11-16
**Branche:** `feature/epic-54-story-2-packs-par-jeu`
**Dépend de:** 54.1.

## Story

En tant qu'admin,
je veux choisir quels packs d'un jeu sont proposés aux joueurs et lequel est ouvert par défaut,
afin que les joueurs tombent sur le bon tracker sans avoir à choisir.

En tant que joueur,
je veux savoir qu'un jeu a un tracker et pouvoir le charger,
afin de m'en servir pendant ma partie.

## Contexte

- 54.1 crée `TrackerPack` et `TrackerPackVersion`, et la section « Trackers » de l'éditeur de jeu.
- Plusieurs packs par jeu sont fréquents : MM a un pack pour la version N64 et un pour Recompiled. Un même pack offre
  plusieurs variantes (`standard`, `itemsonly`, `mapsonly`).
- Fiche publique d'un jeu : `app/(public)/jeux/[slug]/page.tsx`, `features/games/game-detail.tsx`.
- URL présignées MinIO : `$minioPresignTtl` (`config/services.yaml`).

## Décisions

1. **Nouveaux champs** de `TrackerPack` : `enabled` (bool, `false` à l'import), `isDefault` (bool),
   `defaultVariantUid` (nullable, sinon la première variante du manifest), `licenseAcknowledged` (bool).
2. **Activer** : refusé si `licenseSpdx` est nul et que `licenseAcknowledged` est faux
   (`tracker_pack_license_unacknowledged`). L'admin coche « la diffusion de ce pack est autorisée » (avec un lien vers
   le dépôt) pour lever le blocage.
3. **Défaut** : au plus un pack par défaut par jeu, qui doit être actif. Le premier pack activé d'un jeu devient le
   défaut. Désactiver le pack par défaut le retire du défaut, et le pack actif le plus ancien prend sa place.
   Règle portée par une méthode de domaine sur l'ensemble des packs du jeu, avec tests unitaires.
4. **Pas de suppression** : désactiver suffit (même logique que l'epic 46). Les objets MinIO restent.
5. **Endpoints admin** :
   - `PATCH /api/v1/admin/tracker-packs/{packId}` `{enabled?, isDefault?, defaultVariantUid?, licenseAcknowledged?}` ;
   - `defaultVariantUid` inconnu → `tracker_pack_variant_unknown` (422).
6. **Lecture publique** (sans connexion, les packs sont publics sur GitHub et activés par un admin) :
   - `GET /api/v1/games/{gameId}/tracker-packs` → `{data: [{id, name, author, licenseSpdx, sourceUrl, isDefault,
     hasApVariant, version: {id, packageVersion, rootPath, sizeBytes, variants}, defaultVariantUid}]}`, packs actifs
     seulement, le défaut en premier ;
   - `GET /api/v1/tracker-pack-versions/{versionId}/archive` → 302 vers une URL présignée, uniquement si le pack est
     actif (sinon 404, sauf admin, pour le banc d'essai de 54.8). En-tête `Cache-Control: private, max-age` aligné sur
     la durée de présignature.
   - CORS : vérifier que MinIO répond au `fetch` du front sur l'URL présignée (bucket apworlds). Sinon, servir
     l'archive en flux par l'API (`StreamedResponse`) et le noter.
7. **Front admin** : dans la liste des packs, interrupteur « Proposé aux joueurs », étoile « Par défaut », menu de
   variante par défaut, case de licence quand elle manque, lien vers le dépôt.
8. **Fiche publique du jeu** : bloc « Tracker disponible » avec le nom du pack, son auteur, sa licence et un lien vers
   sa source (crédit aux auteurs). Le bouton « Ouvrir le tracker » arrive en 54.5 : dans cette story, le bloc
   n'affiche que les crédits.

## Critères d'acceptation

1. Un pack importé n'est visible d'aucun joueur tant qu'il n'est pas activé.
2. Le pack MM (MIT) s'active directement. Un pack sans licence exige la case cochée.
3. Un jeu a toujours au plus un pack par défaut, et ce pack est actif.
4. L'endpoint public renvoie les packs actifs du jeu, le défaut en premier. L'archive d'un pack actif se télécharge, et
   son `sha256` est celui enregistré.
5. L'archive d'un pack désactivé renvoie 404 à un joueur.
6. La fiche publique d'un jeu affiche les crédits du pack par défaut.

## Tests et gates

7. Unitaires : règles du défaut (activer, désactiver, changer de défaut), blocage de licence.
8. Fonctionnels : PATCH (chaque champ, variante inconnue, non-admin 403), lecture publique (actif ou inactif), archive
   (302 ou flux, 404 si inactif, accès admin).
9. Front : liste admin (interrupteur, défaut, licence), bloc de la fiche publique.
10. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 2, 3, 7) - Champs, migration, règles de domaine.
- [x] **Task 2** (AC 1, 4, 5, 8) - PATCH admin, lecture publique, archive (présignée ou flux).
- [x] **Task 3** (AC 9) - Liste admin enrichie.
- [x] **Task 4** (AC 6) - Bloc de la fiche publique.
- [x] **Task 5** (AC 10) - Gates, vérification réelle (téléchargement depuis le navigateur, CORS).

## Dev Agent Record

**Date:** 2026-11-17

### Décisions

- **Archive servie en flux par l'API**, pas en 302 vers une URL présignée : le tracker lit le zip avec `fetch`, l'API
  répond déjà à l'origine du site, et on ne dépend pas des règles CORS de MinIO. Précédent :
  `PublicPatchDownloadController`. En-têtes : `Content-Type: application/zip`, `ETag` = sha256 de la version,
  `Cache-Control: private, max-age=86400, immutable` (une version ne change jamais ; `private` pour qu'un pack
  désactivé ne traîne pas dans un cache partagé).
- **Règle du défaut** dans `Domain/Service/TrackerPackShelf` (enable, disable, makeDefault sur la liste des packs du
  jeu). Erreur supplémentaire `tracker_pack_not_enabled` (422) quand on met par défaut un pack inactif.
- `isDefault: false` est refusé (400) : le défaut se déplace en choisissant un autre pack ou en désactivant celui-ci.
- Retirer la case licence d'un pack actif sans licence détectée est refusé (`tracker_pack_license_unacknowledged`).
- `defaultVariantUid: null` revient à la première variante.
- La vue publique expose aussi `version.sha256` (vérification d'intégrité et cache côté tracker).
- Fiche publique : le bloc « Tracker disponible » crédite le pack par défaut et liste les autres packs actifs. Une API
  injoignable masque le bloc sans casser la page.

### Fichiers

- API : `TrackerPack` (4 champs + méthodes), `TrackerPackShelf`, `UpdateTrackerPack` + `TrackerPackChanges`,
  `PublicTrackerPackQuery(+Interface, View)`, `TrackerPackArchiveQuery` + `TrackerPackArchive`,
  `DbalPublicTrackerPackQuery`, `TrackerPackVariantRows` (décodage partagé), repository (`find`, `findByGame`,
  `findVersion`, `flush`), `TrackerPackController` (public), route PATCH dans `AdminTrackerPackController`,
  migration `Version20261117100000`.
- Tests API : `tests/Unit/Trackers/TrackerPackShelfTest.php` (9), `tests/Functional/TrackerPackSharingTest.php` (8).
- Front : `admin-tracker-packs-api.ts` (`updateTrackerPack`, champs 54.2), `admin-game-tracker-packs.tsx`
  (`TrackerPackRow` : interrupteur, étoile, variante, case licence), `features/trackers/tracker-packs-api.ts`,
  `features/trackers/game-tracker-credits.tsx`, `game-detail.tsx`, `app/(public)/jeux/[slug]/page.tsx`, et leurs tests.

### Gates

- `./scripts/gates.sh` : api vert (ParaTest) ; front typecheck, lint, 1689 tests et build verts (le build s'arrête une
  fois sur l'avertissement de sécurité de Next.js, sans lien avec la story, et passe au second lancement).

### Vérification réelle (2026-11-17, local)

- Import du pack MM v0.9.5.3 depuis GitHub par l'API locale (MinIO local) : créé, 3 variantes, licence MIT.
- Avant activation : liste publique vide, archive 404 pour un visiteur, 200 pour un admin (7,2 Mo).
- Variante inconnue : 422 `tracker_pack_variant_unknown`. Activation : 204, le pack devient le défaut.
- Après activation : liste publique complète (rootPath `Majoras-Mask-AP-PopTracker-Pack-0.9.5.3`), archive 200 avec
  `Access-Control-Allow-Origin` pour l'origine du site, sha256 du zip égal à l'ETag, 367 fichiers.
- Fiche `/jeux/the-legend-of-zelda-majora-s-mask` : bloc « Tracker disponible » avec nom, version, auteurs, licence MIT
  et lien vers la source.
