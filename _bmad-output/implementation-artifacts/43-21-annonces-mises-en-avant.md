# Story 43.21: Mettre en avant les annonces de parties

**Status:** review
**Epic:** 43 - Amis
**Date:** 2026-10-11

## Story

En tant que membre connecté,
je veux voir les parties qui cherchent des joueurs sans les chercher,
afin d'en rejoindre une au lieu de rater les annonces.

## Contexte

Retour de Jean (2026-10-11). Les annonces (story 43.17) ne s'atteignent qu'en deux clics : « Communauté », puis un
petit bouton sombre sous la carte des amis. Peu de membres les trouvent.

## Critères d'acceptation

1. **Bandeau** : un membre connecté voit « Parties ouvertes » dans le bandeau (bureau, après « Runs hebdos ») et en
   tête du menu téléphone, vers `/communaute/parties`, avec le nombre d'annonces en ligne (rien à zéro). L'entrée
   s'allume sur cette page ; « Communauté » ne s'y allume plus. Un visiteur ne la voit pas.
2. **Communauté** : le petit bouton laisse place à un encart mis en avant (bordure d'accent) « Parties qui cherchent
   des joueurs », avec les deux annonces les plus récentes (rejoindre, signaler) et « Voir les N annonces » quand il
   y en a plus. Sans annonce : une phrase qui invite à publier la sienne. Visiteur : l'invitation à se connecter.
3. Le compteur et l'encart lisent la même requête que la page des annonces (`run-listings`) : rejoindre une partie
   met tout à jour.
4. Gates verts ; tests (entrée allumée sans « Communauté », encart avec ou sans annonces).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 3) - Entrée « Parties ouvertes » et son compteur ; `isActive` gagne des exclusions ; tests.
- [x] **Task 2** (AC 2, 3) - `RunListingsSpotlight` sur la page Communauté ; tests.
- [x] **Task 3** (AC 4) - Gates ; vérification en local.

## Dev Agent Record

- `run-listings.tsx` : `RunListingsCount` (pastille du bandeau), `RunListingsSpotlight` (encart de Communauté, deux
  annonces avec `ListingCard`) ; `RunListingsLink` disparaît.
- `public-shell.tsx` : `ListingsNavLink` (membre connecté seulement) ; `isActive(pathname, href, alsoMatch, except)`,
  « Communauté » exclut `/communaute/parties`.
