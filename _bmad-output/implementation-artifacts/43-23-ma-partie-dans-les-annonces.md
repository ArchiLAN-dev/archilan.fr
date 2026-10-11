# Story 43.23: Voir sa propre partie dans les annonces

**Status:** review
**Epic:** 43 - Amis
**Date:** 2026-10-11

## Story

En tant que créateur d'une partie annoncée,
je veux la voir dans « Parties qui cherchent des joueurs » comme les autres membres la voient,
afin de vérifier mon annonce et la retrouver facilement.

## Contexte

Retour de Jean (2026-10-11). Depuis la story 43.17, la liste des annonces écarte les parties du membre qui la lit :
le créateur ne voit jamais son annonce. Il veut la voir, avec une marque qui dit que c'est la sienne.

## Critères d'acceptation

1. **API** : `GET /run-listings` inclut l'annonce du membre qui lit (mêmes règles de places), avec `isMine: true` ;
   `isMine: false` sur les autres. Une partie rejointe (pas créée) reste écartée.
2. **Carte** : l'annonce du membre porte un badge « Ta partie » et une bordure d'accent ; « Rejoindre » et
   « Signaler » laissent place à « Gérer ma partie » (vers la partie) et « Les autres membres la voient ainsi ».
3. L'encart de Communauté (43.21) et la page des annonces en profitent sans autre changement.
4. Gates verts ; tests (API : propre annonce marquée ; front : badge, pas de rejoindre ni signaler).

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `DbalRunListingsQuery` garde l'annonce du membre ; `isMine` dans `OpenRunListingsQuery` ;
  test fonctionnel.
- [x] **Task 2** (AC 2, 3) - `RunListing.isMine`, `ListingCard` ; test.
- [x] **Task 3** (AC 4) - Gates.

## Dev Agent Record

- `DbalRunListingsQuery` : plus de `owner_id <> :viewer` ; le filtre « déjà dans la partie » épargne le créateur.
- `OpenRunListingsQuery` : `isMine`. Front : `isMine?` (absent sur une ancienne charge utile), badge et lien de gestion.
