# Story 41.27: Un vrai design pour les titres de profil

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-07

## Story

En tant que membre,
je veux que mon titre ait de l'allure, selon sa rareté,
afin qu'un titre difficile à obtenir se voie au premier coup d'oeil.

## Contexte

Jean (2026-10-07) : « Faudrait maintenant qu'on améliore les titres, qu'on ait un vrai design ». Maquette validée
(canvas « Titres de profil - raretés ») avec une brillance oblique, du coin haut gauche au coin bas droit, sur le
légendaire. Première de trois stories (41.28 récompenses cosmétiques, 41.29 collection). Décision : l'admin choisit la
rareté librement, elle ne se déduit pas de la façon d'obtenir le titre.

## Critères d'acceptation

1. **Rareté et icône** : chaque titre a une rareté (commun, rare, épique, légendaire ; commun par défaut) et une icône
   optionnelle (couronne, étoile, épée, gemme, bouclier, flamme, trophée, éclair), choisies par l'admin avec un
   aperçu. Valeur inconnue : 422.
2. **Rendu** (maquette) : commun sobre, rare bleu franc, épique dégradé violet-rose avec halo, légendaire or avec halo
   qui respire et reflet en diagonale ; mouvements coupés pour « réduire les animations ». Deux tailles : profil et
   carte.
3. **Partout** : le titre sur le profil, et en version compacte sur les cartes de l'annuaire, des commentaires et du
   classement ; aussi dans la boutique, la personnalisation et l'admin.
4. **Infobulle** sur le profil (survol et focus clavier) : la rareté et d'où vient le titre (ouvert à tous, adhérents,
   admins, boutique). La 41.28 y ajoutera succès et quêtes.
5. Gates verts ; tests (admin, catalogue, badge sur profil et carte, rendu par rareté, garde de type).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 3) - API : `TitleRarity`, `TitleIcon`, colonnes, admin, catalogue, `badge()` du profil et des
  cartes (annuaire, commentaires, classement), migration ; tests.
- [x] **Task 2** (AC 2-4) - Front : badge par rareté (module CSS), icônes, infobulle, admin, cartes, boutique ; tests.
- [x] **Task 3** (AC 5) - Gates.

## Dev Agent Record

- API : `TitleRarity`, `TitleIcon` (enums) ; `ProfileTitleDefinition` (`rarity`, `icon`, `restyle()`) ;
  `ManageProfileTitles::write/update` (rareté, icône, icône vide = aucune) ; `ProfileTitleCatalog::badge()` et
  `badgeForRow()` (le titre porté : libellé, rareté, icône, accès) ; `CommunityProfileView` sert ce badge ;
  `DbalCommunityUserDirectoryQuery::cards()` et `DbalLeaderboardQuery` lisent `cp.title_key` ; l'annuaire, les
  auteurs de commentaires et le classement transmettent `title`. Migration `Version20261007120000`.
- Front : `profile-title-badge.tsx` + `.module.css` (raretés, icônes en trait, brillance oblique, halo, infobulle
  accessible) ; types `TitleRarity`, `TitleIcon`, `TitleBadge`, `isTitleBadge`, `badgeOf`, `titleOrigin` ;
  admin (rareté, icône, aperçu deux tailles) ; carte de l'annuaire, commentaires, classement ; boutique
  (`useTitleBadge`), personnalisation, profil (infobulle).
- `composer gates` (2 802) et `pnpm gates` (862) verts.
