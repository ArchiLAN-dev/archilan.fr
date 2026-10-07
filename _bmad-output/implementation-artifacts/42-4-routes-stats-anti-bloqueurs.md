# Story 42.4: Routes des statistiques à l'abri des bloqueurs de pub

**Status:** review
**Epic:** 42 - Statistiques admin
**Date:** 2026-10-04

## Story

En tant qu'admin qui utilise une extension anti-pub,
je veux que toutes les sections de `/admin/statistiques` se chargent,
afin de ne pas voir « Impossible de charger cette section » sans raison.

## Contexte

Le 2026-10-04, la section Événements ne se chargeait pas en local : l'extension anti-pub de Jean bloquait
`GET /api/v1/admin/stats/events` dans le navigateur (la requête n'atteignait jamais l'API, les trois autres
sections passaient). Les listes de filtres visent les chemins qui ressemblent à de la mesure d'audience
(`/stats/events` en tête). Tout admin équipé d'un bloqueur est touché, en prod comme en local.

## Critères d'acceptation

1. Les quatre sections sont servies sous `/api/v1/admin/statistiques/{section}`, avec des noms de section en
   français : `communaute`, `parties`, `evenements`, `pelles`. Les anciennes routes `/api/v1/admin/stats/*`
   disparaissent (seule la page admin les appelle, livrée avec l'API).
2. La page `/admin/statistiques` appelle les nouvelles routes ; aucun comportement ne change par ailleurs.
3. Tests fonctionnels et tests front mis à jour. Gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Routes des quatre contrôleurs, tests fonctionnels.
- [x] **Task 2** (AC 2) - `fetchSection` et ses appels, tests front.
- [x] **Task 3** (AC 3) - Gates.

## Notes techniques

- Pour toute nouvelle route : éviter `stats/events`, `track`, `analytics`, `pixel`, `beacon` et voisins dans le
  chemin.
