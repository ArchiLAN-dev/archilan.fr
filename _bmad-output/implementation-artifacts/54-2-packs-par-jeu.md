# Story 54.2 - Les packs d'un jeu

**Status:** ready-for-dev
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

- [ ] **Task 1** (AC 2, 3, 7) - Champs, migration, règles de domaine.
- [ ] **Task 2** (AC 1, 4, 5, 8) - PATCH admin, lecture publique, archive (présignée ou flux).
- [ ] **Task 3** (AC 9) - Liste admin enrichie.
- [ ] **Task 4** (AC 6) - Bloc de la fiche publique.
- [ ] **Task 5** (AC 10) - Gates, vérification réelle (téléchargement depuis le navigateur, CORS).
