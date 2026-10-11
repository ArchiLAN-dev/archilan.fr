# Story 43.24: Annonces de parties visibles sans compte

**Status:** review
**Epic:** 43 - Amis
**Date:** 2026-10-11

## Story

En tant que visiteur,
je veux voir les parties qui cherchent des joueurs sans être connecté,
afin de découvrir qu'on peut jouer avec la communauté et créer un compte pour en rejoindre une.

## Contexte

Retour de Jean (2026-10-11). Depuis la story 43.17, la liste des annonces et l'encart de Communauté (43.21) demandent
d'être connecté : un visiteur ne voit qu'une invitation à se connecter.

## Critères d'acceptation

1. **API** : `GET /run-listings` répond aussi sans session : toutes les annonces en ligne (mêmes règles de places et
   de comptes sanctionnés), sans amis (`isOwnerFriend: false`, `friendsIn: []`) ni `isMine`. Rejoindre et signaler
   restent réservés aux membres (401).
2. **Front** : la page des annonces et l'encart de Communauté montrent les annonces à un visiteur. Sur chaque carte,
   « Se connecter pour rejoindre » (retour sur les annonces) remplace « Rejoindre » ; pas de « Signaler ».
3. La requête est propre à chaque lecteur (visiteur ou membre) : se connecter ne montre pas la liste du visiteur.
4. La page reste hors de l'index des moteurs (contenu de membres, éphémère).
5. Gates verts ; tests (API visiteur, carte visiteur, encart visiteur).

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `optionalUser` dans le contrôleur ; `forViewer(?string)` et `listingsFor(?string)` ; test.
- [x] **Task 2** (AC 2, 3, 4) - `RunListingsBoard`, `RunListingsSpotlight`, `ListingCard` ; `runListingsKey(userId)` ;
  tests.
- [x] **Task 3** (AC 5) - Gates ; vérification en local (visiteur).

## Dev Agent Record

- `RunOpennessController::listings` : `optionalUser`. `DbalRunListingsQuery` : filtres de blocage et de participation
  seulement pour un membre.
- Front : `runListingsKey(userId)` (les invalidations par `RUN_LISTINGS_KEY` restent valables par préfixe).
