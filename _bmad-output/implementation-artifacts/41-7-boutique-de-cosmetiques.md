# Story 41.7: Boutique de cosmétiques

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant que membre,
je veux acheter des cadres et des bannières avec mes pelles en or,
afin de personnaliser mon profil avec ce que j'ai gagné.

En tant qu'admin,
je veux mettre en vente des cosmétiques, à un prix et pour une période donnés,
afin de proposer des objets permanents et saisonniers (ArchiLAN).

## Contexte

**Décision 5 de l'epic (Jean, 2026-10-03)** : des cadres et bannières achetables par tous, hors des avantages
réservés aux adhérents et admins (stories 30.40 à 30.46), plus des objets saisonniers.

Aujourd'hui les 14 cadres classiques et les 10 bannières prédéfinies sont gratuits pour tous ; les vendre les
retirerait aux membres. La boutique vend donc des cosmétiques **nouveaux**, marqués « de boutique » dans le
catalogue du code. Ils doivent être dessinés par des membres (règle de l'équipe : aucun visuel généré par IA) :
**décision de Jean (2026-10-03), la mécanique est livrée maintenant, avec un catalogue vide** ; chaque visuel de
membre s'ajoutera au code, puis l'admin le mettra en vente.

## Critères d'acceptation

### Catalogue

1. Le catalogue du code distingue, pour les cadres et les bannières, les clés « de boutique » (`AvatarFrame::SHOP`,
   `BannerPreset::SHOP`, vides au départ). Une clé de boutique n'est utilisable que par qui la possède ; les autres
   gardent leurs règles actuelles (gratuites, ou réservées aux admins pour les Légendaires).
2. La possession est permanente (table `owned_cosmetic`, une ligne par membre, type et clé).

### Admin

3. Page admin « Boutique » : liste des articles (en vente, à venir, terminés, retirés) ; mise en vente d'une clé de
   boutique (type, clé, prix de 1 à 10 000 pelles, début et fin facultatifs pour un objet saisonnier) ; retrait
   d'un article. Une clé qui n'est pas « de boutique » est refusée (422).

### Membre

4. Page « Boutique » : les articles en vente (fenêtre ouverte, non retirés), leur prix, « Possédé » pour ce que le
   membre a déjà, et pour un objet saisonnier sa date de fin.
5. Achat : débit des pelles en or (motif `shop_purchase`, clé par membre et article) et possession écrits dans la
   même transaction ; refusé si l'article n'est pas en vente (409), déjà possédé (409), ou si le solde ne suffit pas
   (422, avec le solde). Un compte banni n'achète pas.
6. Éditeur de profil : un cadre ou une bannière de boutique non possédé apparaît verrouillé (« En boutique »), et
   l'API refuse de l'enregistrer.
7. Le motif `shop_purchase` a un libellé ; « Boutique » apparaît dans le menu du compte et la navigation admin.

### Qualité

8. Gates : `composer gates` et `pnpm gates` verts.

## Notes techniques

- La boutique vit dans Wallet. Community a besoin des clés possédées pour valider le profil : il définit un port
  (`CosmeticOwnershipInterface`, Application) que Wallet implémente en Infrastructure. Community ne dépend
  jamais de Wallet.
- Ajouter un cosmétique de boutique : sa clé dans `ALL` et `SHOP` (backend), son rendu dans `avatar-frames.ts` /
  `banner-presets.ts` (front, avec `shop: true`), puis la mise en vente par l'admin.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 6) - Clés de boutique du catalogue, port `CosmeticOwnershipInterface`, validation du profil.
- [x] **Task 2** (AC 3-5) - `ShopItem`, `OwnedCosmetic`, migration Version20261003170000, `ManageShop`, `BuyShopItem`,
  `ShopQuery`, endpoints ; tests.
- [x] **Task 3** (AC 4, 6, 7) - Front : `/compte/boutique`, `/admin/boutique`, verrous par clé dans l'éditeur de profil,
  libellé, navigation ; tests.
- [x] **Task 4** (AC 8) - Gates.

## Dev Agent Record

- `AvatarFrame::SHOP` et `BannerPreset::SHOP` sont vides : la boutique est livrée sans article (décision de Jean). Les
  tests listent des articles directement par le dépôt.
- `DoctrineShopRepository` implémente à la fois le dépôt Wallet et le port Community de possession.
- Endpoints : `GET /api/v1/shop`, `POST /api/v1/shop/items/{id}/buy`, `GET|POST /api/v1/admin/shop/items`,
  `DELETE /api/v1/admin/shop/items/{id}` ; l'éditeur de profil reçoit `ownedFrames` et `ownedBanners`.
- Fusion avec la pile de l'epic 42 : `admin-shell.tsx` ajoute « Boutique » à côté de la ligne « Pelles » que la 42.1
  retire ; conflit trivial à résoudre en gardant « Boutique » sans « Pelles ».
