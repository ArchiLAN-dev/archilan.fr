# Story 41.22: Titres de profil achetables

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant que membre,
je veux acheter un titre de profil avec mes pelles et le porter sous mon pseudo,
afin d'avoir de quoi dépenser mes pelles sans attendre de nouveaux visuels.

En tant qu'admin,
je veux écrire les titres et choisir qui peut les porter,
afin d'alimenter la boutique sans dessinateur.

## Contexte

Les pelles se gagnent de plus en plus (quêtes, coffre, primes, événements) mais la boutique ne vend que des cadres
et des bannières, qui demandent des visuels dessinés par des membres. Piste proposée par Claude, retenue par Jean
(2026-10-06) avec la couleur de pseudo (41.23). Un titre acheté est un **cosmétique** ; il ne remplace pas le titre
de statut (« Administrateur », « Adhérent ArchiLAN », story 30.44), calculé, qui reste au-dessus du pseudo.

## Critères d'acceptation

1. **Catalogue de titres** (admin, page « Titres ») : un titre = une clé, un libellé (2 à 40 caractères), un accès
   (tout le monde, adhérents, admins, boutique), une position ; créer, renommer, changer l'accès, retirer,
   rétablir. Un titre retiré ne se choisit plus et disparaît des profils qui le portaient.
2. **Boutique** : un titre en accès « boutique » se met en vente comme un cadre ou une bannière (nouveau type
   `title`), s'achète en pelles d'or, s'affiche comme une étiquette de texte (aperçu, essayage).
3. **Profil** : dans la personnalisation, le membre choisit un titre parmi ceux qu'il peut porter (ou aucun) ;
   l'API refuse un titre inconnu, retiré ou non autorisé (même règle que les cadres : un titre déjà porté n'est
   pas revérifié à l'enregistrement).
4. **Affichage** : sur la page de profil, le titre porté apparaît sous le pseudo.
5. Gates verts ; tests (catalogue admin, achat, choix autorisé / refusé, affichage).

## Notes techniques

- Contexte Community : `ProfileTitleDefinition` (table `profile_title`), `ProfileTitleCatalog` (comme
  `ProfileBannerCatalog`, sans fichiers), colonne `community_profile.title_key`.
- Wallet : `ShopItem::TYPE_TITLE`, `ShopCatalog::sellable()` y ajoute les titres en boutique,
  `CosmeticOwnershipInterface::TITLE`.
- Hors périmètre : le titre sur les cartes (annuaire, classement...) - les cartes passent par plusieurs requêtes,
  à faire ensuite si le titre prend.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Domaine, migration, catalogue, API admin et publique ; tests.
- [x] **Task 2** (AC 2) - Boutique : type `title` ; tests.
- [x] **Task 3** (AC 3, 4) - Profil : choix et affichage ; tests.
- [x] **Task 4** (AC 1-4) - Front : page admin, boutique, personnalisation, page de profil ; tests.
- [x] **Task 5** (AC 5) - Gates, vérification dans l'app.

## Dev Agent Record

- API : `ProfileTitleDefinition` (table `profile_title`), `ProfileTitleCatalog`, `ManageProfileTitles`,
  `ProfileTitleCatalogQuery`, `ProfileTitleController` (`GET /api/v1/profile-titles`, admin `GET`/`POST
  /api/v1/admin/profile-titles`, `PATCH /{key}`, `POST /{key}/retire|restore`) ; `community_profile.title_key`,
  `CommunityProfile::wearTitle()` ; `PUT /api/v1/community/profile` accepte `title` (absent = inchangé, vide = retiré).
  Boutique : `ShopItem::TYPE_TITLE`, `ShopCatalog`, libellé d'achat « titre ». Migration `Version20261006140000`.
- Front : `profile-title-catalog.ts`, `profile-title-badge.tsx`, page `/admin/titres` (menu Communauté),
  `ProfileTitleField` dans la personnalisation, titre sous le pseudo sur la page de profil, aperçu et essayage en
  boutique.
- Hors périmètre : le titre sur les cartes (annuaire, classement...).
- `composer gates` (2 784) et `pnpm gates` (846) verts ; page admin vérifiée sur le serveur de dev (sans créer de titre).
