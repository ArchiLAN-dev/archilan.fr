# Story 30.40: Bannière image et photo de profil animée, selon le statut

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-09-30

## Story

En tant qu'adhérent ou administrateur d'ArchiLAN,
je veux mettre une image comme bannière de mon profil (et, admin, une bannière ou une photo animée en GIF),
afin de personnaliser mon profil au-delà des bannières prédéfinies.

## Contexte

Demande de Jean (2026-09-30). Aujourd'hui : bannières prédéfinies seulement (`BannerPreset`, « no image upload
this epic ») ; photo de profil envoyable par tout membre (story 30.27 : JPEG / PNG / WebP, 5 Mo, MinIO, URL
presignée, GIF refusé). Choix de Jean : bannière image pour les adhérents (image fixe) et les admins (GIF
compris) ; photo animée (GIF) pour les admins ; un admin rétrogradé garde la première image de son GIF.

## Règles

| | Photo de profil | Bannière image |
|---|---|---|
| Admin | JPEG, PNG, WebP (5 Mo), GIF (10 Mo) | JPEG, PNG, WebP, GIF (10 Mo) |
| Adhérent (adhésion active) | JPEG, PNG, WebP (5 Mo) | JPEG, PNG, WebP (10 Mo) |
| Autre membre | JPEG, PNG, WebP (5 Mo) | bannières prédéfinies seulement |

- L'animation passe uniquement par le GIF : WebP animé et PNG animé (APNG) sont refusés pour tout le monde.
- Le format est lu dans le contenu du fichier, jamais dans son nom. SVG refusé.
- Un GIF est stocké tel quel (l'animation est conservée) et sa **première image** est extraite à l'envoi, en PNG.

## Critères d'acceptation

1. **Envoi de bannière** : `POST /api/v1/community/profile/banner` (multipart `file`), `DELETE` pour la retirer.
   Refus explicites : non autorisé (ni adhérent ni admin), GIF par un non-admin, animation hors GIF, format non
   pris en charge, trop lourd.
2. **Photo de profil** : même route qu'avant ; un admin peut envoyer un GIF (10 Mo), les autres gardent les
   règles actuelles ; WebP / PNG animés refusés.
3. **Affichage selon le statut, décidé à la lecture** (aucune donnée effacée) :
   - photo : GIF d'un compte qui n'est plus admin → sa première image ; sinon le fichier envoyé ;
   - bannière : admin → le fichier envoyé ; adhérent → la première image si c'est un GIF, sinon le fichier ;
     ni l'un ni l'autre → pas d'image, la bannière prédéfinie choisie s'affiche. Tout revient si le statut
     revient.
4. **Profil public** : la bannière image couvre la zone de bannière (recadrée), avec le voile qui garde le
   pseudo lisible ; pour un visiteur qui demande moins d'animation, la première image remplace le GIF.
5. **Personnalisation du profil** : section « Image de bannière » (adhérents et admins) avec envoi, aperçu,
   retrait et les formats autorisés selon le statut ; le sélecteur de photo accepte le GIF pour un admin.
6. Mêmes garanties que la photo : stockage MinIO (bucket média), URL presignée, échec de stockage = 503 sans rien
   modifier ; les anciens fichiers restent en place (pas de suppression sur le port de stockage).
7. `composer gates` et `pnpm gates` passent ; l'image `api-web` gagne l'extension `gd` (extraction de la première
   image).

## Tasks / Subtasks

- [x] **Task 1** (Règles, AC 1, 2) - `ImageInspector` (format et animation par le contenu), `CustomImageRule`
      (autorisations, poids, image affichée), tests.
- [x] **Task 2** (AC 1, 2, 6) - Port `ImageStillExtractor` + adaptateur GD ; colonnes et migration ; service
      d'envoi (photo, bannière) ; contrôleurs ; tests fonctionnels.
- [x] **Task 3** (AC 3) - Image affichée selon le statut dans la vue de profil, l'annuaire et les classements.
- [x] **Task 4** (AC 4, 5) - Front : bannière image, formulaire, API ; tests.
- [x] **Task 5** (AC 7) - Gates, image Docker.

## Dev Agent Record

- **Domaine** (`Community`) : `ImageInspector` (format et animation lus dans les octets : images d'un GIF comptées,
  `acTL` d'un APNG, drapeau d'animation d'un WebP VP8X ; SVG et le reste refusés), `CustomImageRule`
  (autorisations, poids max, image affichée selon le statut), enums `ImageFormat`, `CustomImageSlot`,
  `CustomImageRefusal`, `InspectedImage`. Entité : `custom_avatar_still_key`, `custom_banner_key`,
  `custom_banner_still_key` (migration `Version20260930100000`).
- **Application** : `CommunityCustomImageService` remplace `CommunityAvatarService` (photo et bannière, règles,
  stockage du GIF et de sa première image, 503 si le stockage tombe, rien d'effacé) ; port `ImageStillExtractor`.
  `CommunityProfileView` : photo et bannière affichées selon le statut (`badges`), champs d'édition
  `bannerImageUrl`, `bannerImageStillUrl`, `hasCustomBanner`, `bannerUpload`, `avatarGifAllowed`.
  `AvatarUrlResolver::resolveForRow` pour l'annuaire et les classements (rôles lus sur la ligne utilisateur).
- **Infrastructure** : `GdImageStillExtractor` (GD lit la première image d'un GIF, réécrite en PNG) ; `gd` ajouté à
  l'image `api-web` et à la CI backend.
- **Présentation** : `CommunityCustomImageController` remplace `CommunityAvatarController` (mêmes routes photo,
  nouvelles routes `POST` / `DELETE /api/v1/community/profile/banner`), codes d'erreur `banner_not_allowed` (403),
  `image_gif_admin_only`, `image_animation_unsupported`, `image_invalid_type`, `image_too_large` (422).
- **Front** : `ProfileBanner` en mode image (`<picture>` : première image pour « réduire les animations »,
  confinée par `relative overflow-hidden`), profil public, API (`uploadCommunityBanner`, `removeCommunityBanner`,
  code de refus renvoyé), formulaire (section « Image de bannière » pour adhérents et admins, GIF proposé selon le
  statut, messages d'erreur précis), `custom-image-rules.ts`.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `CustomImageRuleTest`, `ImageInspectorTest` | classes absentes | 13 verts |
| `GdImageStillExtractorTest` | classe absente | 2 verts |
| `CommunityCustomImageTest` (fonctionnel : bannière adhérent, refus, GIF admin, première image, rétrogradation, retrait) | routes absentes | 6 verts (+ 5 `CommunityAvatarTest` inchangés, verts) |
| `custom-image-rules.test.ts`, `profile-banner.test.tsx`, `community-avatar-api.test.ts` | modules / formes absents | 14 verts |

Gates : `composer gates` (2518 tests), `pnpm gates` (650 tests, build) verts.

### Vérifications

- **Image `api-web`** construite en local avec `gd` : extension chargée, extraction réelle dans l'image (GIF reconnu,
  première image en PNG 8x4).
- **Rendu** du profil avec une image de bannière (données d'exemple, CSS du build) : l'image reste dans la bannière.
  Le voile d'assombrissement vient du module CSS, absent de ce rendu de test mais présent dans le build.
- **Non vérifié** : un envoi réel depuis le formulaire (demande l'API du worktree et MinIO) ; à faire après merge.
