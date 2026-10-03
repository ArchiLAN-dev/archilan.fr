# Story 41.10: Cadres vidéo gérés par l'admin

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant qu'admin,
je veux ajouter un cadre d'avatar vidéo depuis l'administration, choisir à qui il est ouvert et le mettre en vente,
afin qu'un dessin de membre arrive dans la boutique sans développeur ni release.

## Contexte

Aujourd'hui un cadre se déclare dans le code (front `AVATAR_FRAMES`, API `AvatarFrame::ALL`) : un nouvel objet de
boutique demande une story et une release (41.7). Les cadres vidéo (story 30.46) ont un rendu générique : une vidéo
de lumière sur fond noir posée en `mix-blend-mode: screen`, sur une géométrie commune (512 px, ouverture
107..403 x 111..407), une seule règle CSS. Ce qui distingue un cadre vidéo d'un autre, ce sont ses fichiers et son
nom. Décision de Jean (2026-10-03) : les cadres vidéo deviennent un catalogue géré par l'admin.

Les 14 cadres CSS (couleurs, néon, effets) restent dans le code : ce ne sont pas des vidéos, et ils sont gratuits.

## Critères d'acceptation

### Catalogue

1. Table `avatar_frame` : clé (slug unique, minuscules, chiffres et `_`), nom, accès (`free`, `members`, `admins`,
   `shop`), clés MinIO des fichiers (`webm`, `mp4`, `poster`, `still`), ordre d'affichage, date de création, date
   de retrait. Une clé ne doit pas reprendre celle d'un cadre du code.
2. **Accès** :
   - `free` : tout le monde ;
   - `members` : adhérents à jour et admins ;
   - `admins` : admins ;
   - `shop` : qui l'a acheté (possession de la 41.7).
   La validation du profil (`UpdateCommunityProfile`) et l'affichage (`AvatarFrame::displayed`) appliquent ces
   règles : un cadre retiré, ou devenu inaccessible (adhésion échue, plus admin), n'est plus montré ; la clé
   enregistrée est gardée et le cadre revient avec le droit.
3. **Migration des 7 Légendaires** (`fire`, `electric`, `spectral_fire`, `lava`, `runes`, `cosmic`, `glitch`) dans le
   catalogue, mêmes clés, accès `admins` : les profils qui les portent ne changent pas. Leurs fichiers restent servis
   depuis `public/avatar-frames/` (chemin relatif enregistré à la place d'une clé MinIO) ; seuls les nouveaux cadres
   vont dans MinIO.

### Admin

4. Page admin « Cadres » (`/admin/cadres`) : liste avec aperçu (le poster sur une photo de démonstration), accès et
   état ; ajout d'un cadre avec le téléversement des 4 fichiers préparés (recette Kling + ffmpeg de la story 30.46,
   documentée sur la page) ; changement d'accès, de nom et d'ordre ; retrait. Pas de suppression : un cadre porté
   ou acheté reste lisible.
5. Validation des fichiers à l'envoi : `webm` (VP9) et `mp4` (H.264) de 3 Mo au plus chacun, `poster` et `still` en
   WebP de 512 x 512 exactement. L'API ne convertit rien (pas de ffmpeg dans l'image) : un fichier hors format est
   refusé avec la raison.
6. Un cadre `shop` peut être mis en vente dans `/admin/boutique` (41.7) comme un cadre du code marqué boutique :
   `ShopCatalog::sellable()` inclut les cadres `shop` du catalogue.

### Affichage

7. `GET /api/v1/avatar-frames` (public, mis en cache) : les cadres du catalogue non retirés, avec leurs URL de
   fichiers, leur nom et leur accès.
8. Front : `getAvatarFrame` garde le catalogue du code ; une clé inconnue de lui est résolue par une couche client
   qui lit le catalogue de l'API (TanStack Query, longue durée de validité). Le rendu côté serveur montre l'avatar
   sans cadre pour une clé du catalogue, le cadre apparaît à l'hydratation (les vidéos se chargent déjà côté client).
9. Sélecteur de cadres : les cadres du catalogue rejoignent la catégorie « Légendaires », verrouillés selon leur
   accès (« Réservé aux admins », « Réservé aux adhérents », « En boutique »).

### Qualité

10. Gates : `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - Entité, migration (dont les 7 Légendaires), règles d'accès, validation du profil.
- [x] **Task 2** (AC 4-6) - Téléversement et validation des fichiers, commandes admin, endpoints ; tests.
- [x] **Task 3** (AC 7-9) - Endpoint public, résolution côté client, sélecteur, page admin ; tests.
- [x] **Task 4** (AC 10) - Gates.

## Notes techniques

- Le catalogue vit dans Community (les cadres sont des cosmétiques du profil) ; la possession reste dans Wallet,
  lue par le port `CosmeticOwnershipInterface` (41.7).
- Stockage : le même bucket que les images de profil (stories 30.40-30.43), servi par URL publique.
- Le contrôle des dimensions du WebP se fait sur l'image ; pour les vidéos, l'API ne vérifie que le type et le
  poids : c'est la recette qui garantit la géométrie.

## Dev Agent Record

### Écarts au plan (validés à l'implémentation)

- **Légendaires : pas de recopie en base.** Les 7 cadres de la story 30.46 restent dans le code (fichiers dans
  `public/avatar-frames/`). Une ligne `avatar_frame` n'est créée pour eux qu'au premier changement d'accès, de nom ou
  d'ordre (surcharge sans fichiers, `builtIn: true`). Aucune migration de données, et un cadre du code jamais touché
  garde sa règle d'origine (admins).
- **Adhérents et boutique vérifiés au choix seulement.** Un cadre retiré disparaît des profils ; un cadre « adhérents »
  porté par un membre dont l'adhésion expire, ou un cadre « boutique » porté puis retiré de la vente, reste affiché :
  la vérification se fait quand le membre choisit son cadre, pas à chaque affichage (pas de lecture d'adhésion par carte).

### Fichiers

- API : `Community` (entité `AvatarFrameDefinition`, enum `AvatarFrameAccess`, `AvatarFrameCatalog`,
  `AvatarFrameFileRule`, `ManageAvatarFrames`, `AvatarFrameCatalogQuery`, `AvatarFrameController`), migration
  `Version20261003200000`, `ShopCatalog` (Wallet) qui lit les cadres boutique du catalogue ; `AvatarFrameCatalogTest`.
- Front : `avatar-frame-catalog.ts`, `catalog-avatar-frame.tsx`, sélecteur (verrou par cadre), page `/admin/cadres`
  (`admin-avatar-frames.tsx`) et entrée de menu ; tests.

