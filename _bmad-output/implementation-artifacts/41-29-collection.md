# Story 41.29: Ma collection

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-07

## Story

En tant que membre,
je veux voir tous les cosmétiques du site, ceux que j'ai et comment obtenir les autres,
afin de savoir quoi viser.

## Contexte

Troisième des trois stories (41.27 design des titres, 41.28 récompenses), retenue par Jean le 2026-10-07. Décision
annoncée : une section « Collection » de « Mon espace » plutôt qu'un onglet du profil public. Les cosmétiques
verrouillés n'y ont de sens que pour le membre lui-même.

## Critères d'acceptation

1. **Tout le catalogue** : titres, couleurs de pseudo, cadres et bannières (hors retirés), chacun avec son statut :
   obtenu (et son origine : succès, quête, boutique), disponible (gratuit, ou réservé à un statut que le membre a), ou à
   débloquer.
2. **Comment l'obtenir** : les succès actifs et les quêtes encore en jeu qui le débloquent (avec leur description), la
   boutique et son prix du moment (ou « Pas encore en vente »), le statut requis (adhérents, admins).
3. **Page** `/compte/collection` : progression (obtenus / total), filtres par type et par statut, aperçu de chaque
   cosmétique sur l'avatar et le pseudo du membre (titres à leur rareté), lien vers la boutique et vers le profil pour
   les porter. Entrée « Collection » dans le menu du compte.
4. Gates verts ; tests.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - API : `MyCollection`, `GET /api/v1/me/collection` ; tests.
- [x] **Task 2** (AC 3) - Front : `collection-api.ts`, `collection-page.tsx`, route, menu ; tests.
- [x] **Task 3** (AC 4) - Gates.

## Dev Agent Record

- API : `Wallet\Application\Query\MyCollection` (catalogues de titres, cadres, bannières, `NameColor` ; possession et
  origine par le port `CosmeticOwnershipInterface::origins()` ; boutique en vente, succès actifs et quêtes non retirées
  qui débloquent chaque cosmétique ; droits par l'adhésion active et le rôle admin) ; route
  `WalletController::collection()`.
- Front : `collection-api.ts` (types et gardes, `originText`), `collection-page.tsx` (progression, filtres, cartes
  avec `ShopCosmeticPreview`, façons de débloquer), `/compte/collection`, menu du compte.
- Tests : `MyCollectionTest` (statuts, façons de débloquer, prix, origine après attribution, accès réservé au membre),
  `collection.test.tsx`. `composer gates` (2 807) et `pnpm gates` (870) verts.
