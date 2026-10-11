# Story 43.26: Badge des parties ouvertes sur « Communauté »

**Status:** review
**Epic:** 43 - Amis
**Date:** 2026-10-11

## Story

En tant que membre ou visiteur,
je veux voir depuis n'importe quelle page qu'il y a des parties qui cherchent des joueurs,
afin d'aller les voir sur Communauté.

## Contexte

Idée de Jean (2026-10-11), après avoir écarté une entrée dédiée dans le bandeau (43.21) : un petit badge sur
« Communauté », comme le solde de pelles à côté de « Boutique ».

## Critères d'acceptation

1. À côté de « Communauté » (bureau et menu téléphone), une pastille verte avec le nombre d'annonces que le lecteur
   pourrait rejoindre : sa propre annonce n'est pas comptée. Rien à zéro.
2. Visiteurs compris (les annonces leur sont ouvertes depuis 43.24).
3. Même requête que la page des annonces et l'encart (`runListingsKey`) : rejoindre ou publier met le badge à jour.
4. Un lecteur d'écran entend « N parties cherchent des joueurs ».
5. Gates verts ; tests (compte sans sa propre annonce, rien à zéro, visiteur).

## Tasks / Subtasks

- [x] **Task 1** (AC 1 à 4) - `joinableCount`, `RunListingsBadge` ; suffixe des deux entrées « Communauté » ; tests.
- [x] **Task 2** (AC 5) - Gates ; vérification en local (visiteur).

## Dev Agent Record

- `run-listings.tsx` : `joinableCount`, `RunListingsBadge` (point vert animé, texte masqué pour lecteur d'écran).
- `public-shell.tsx` : `suffix={<RunListingsBadge />}` sur « Communauté ».
