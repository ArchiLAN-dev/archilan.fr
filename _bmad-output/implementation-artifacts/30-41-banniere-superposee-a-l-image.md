# Story 30.41: Bannière prédéfinie superposée à l'image de bannière

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-09-30

## Story

En tant qu'adhérent ou administrateur qui a mis une image (ou un GIF) en bannière,
je veux que ma bannière prédéfinie se pose par-dessus, en transparence, avec une intensité que je règle,
afin de cumuler l'animation du dégradé et mon image.

## Contexte

Suite de la story 30.40 (demande de Jean, 2026-09-30). Aujourd'hui l'image de bannière remplace la bannière
prédéfinie.

## Critères d'acceptation

1. **Superposition** : avec une image de bannière, l'image est dessous, la bannière prédéfinie choisie (dégradé,
   taches, texture) par-dessus avec une opacité réglable, puis le voile qui garde le pseudo lisible.
2. **Intensité** de 0 % (l'image seule) à 100 % (la bannière couvre l'image), 50 % par défaut, enregistrée avec
   le profil (`bannerOverlay`, entier 0 à 100 ; hors bornes = refus « Intensité invalide. » ; absente = inchangée).
3. **Personnalisation** : curseur « Intensité de la bannière » affiché quand une image de bannière est en place ;
   l'aperçu suit le curseur ; enregistré par la barre d'enregistrement comme le reste du profil.
4. **Statut** : si l'image n'est plus affichée (story 30.40), la bannière prédéfinie s'affiche seule, pleine.
5. « Réduire les animations » : la première image remplace le GIF (30.40) et le dégradé ne bouge plus (déjà le cas).
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 2) - `BannerOverlay`, colonne `banner_overlay` + migration, validation, exposition ; tests.
- [x] **Task 2** (AC 1, 4, 5) - `ProfileBanner` : image + bannière en transparence ; profil public ; tests.
- [x] **Task 3** (AC 3) - Curseur dans la personnalisation.
- [x] **Task 4** (AC 6) - Gates et vérification visuelle.

## Dev Agent Record

- **API** : `BannerOverlay` (0 à 100, défaut 50), colonne `banner_overlay` (migration `Version20260930140000`),
  `CommunityProfile::adjustBannerOverlay()`, champ facultatif `bannerOverlay` dans la mise à jour du profil
  (absent = inchangé, hors bornes ou non entier = 422 « Intensité invalide. »), exposé dans le profil public
  (`customization.bannerOverlay`) et l'édition.
- **Front** : `ProfileBanner` en mode image pose les couches du preset (dégradé, taches, texture) au-dessus de
  l'image, dans un calque à `overlay` % d'opacité, voile au-dessus de tout ; 0 % = pas de calque. Profil public
  et personnalisation : curseur « Intensité de la bannière » (pas de 5 %) quand une image est en place,
  aperçu en direct, enregistré par la barre d'enregistrement.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `CommunityBannerOverlayTest` (défaut, enregistrement, absent = inchangé, refus) | 3 échecs | 3 verts |
| `profile-banner.test.tsx` (calque à 30 %, rien à 0 %) | 1 échec | verts |

Gates : `composer gates` (2521 tests), `pnpm gates` (652 tests, build) verts.

### Vérification visuelle

Non faite : le rendu de test (jest) ne charge pas le module CSS des bannières, donc pas de dégradé visible.
À regarder sur le serveur de dev (bascule du dossier principal sur la branche + migration).
