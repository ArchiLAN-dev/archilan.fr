# Story 53.6 - Avant, pendant, après

**Status:** ready-for-dev
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-6-avant-pendant-apres` (depuis `develop`)
**Dépend de:** 53.2, 53.3 (et 53.4, 53.5 si livrées : leurs sections sont ordonnées aussi).

## Story

En tant qu'inscrit,
je veux que mon espace mette en avant ce qui compte maintenant (préparer avant, jouer pendant, revivre après),
afin de ne pas dérouler toute la page sur mon téléphone en pleine LAN.

## Contexte

- `eventPhase` (front, 53.1) et `phase` renvoyé par la checklist (53.2).
- Après l'événement : résultats `/evenements/[slug]/resultats`, récap de session (`getEventRecapIndex`,
  `features/recap/recap-api.server`), sondages de public `participants` (epic 51), VOD `Event::vodUrl`.

## Décisions

1. **Ordre des sections** par moment :
   - `before` : checklist, infos pratiques, outils, guides.
   - `during` : outils (partie en tête), infos pratiques, checklist **repliée** (dépliable, avec son compteur),
     guides.
   - `after` : « Revivre l'event » (nouvelle section), sondages, guides ; checklist, infos pratiques et outils
     masqués, sauf les sondages et la carte pelles.
2. **« Revivre l'event »** : liens vers les résultats, le récap de ma partie (s'il existe), la VOD (si `vodUrl`),
   les photos (si `photoGallery`).
3. **Rafraîchissement du moment** : recalcul à chaque minute côté client (la page reste ouverte pendant la LAN),
   sans recharger les sections déjà affichées.
4. Une fonction pure `hubSectionOrder(phase)` porte l'ordre, testée seule.

## Critères d'acceptation

1. Avant : la checklist est en haut.
2. Pendant : les outils sont en haut, la checklist repliée affiche « 4 sur 5 ».
3. Après : la section « Revivre l'event » est en haut, avec seulement les liens disponibles.
4. Le passage de `before` à `during` se fait sans recharger la page.

## Tests et gates

5. Unitaires : `hubSectionOrder`, liens de « Revivre l'event » selon les données présentes.
6. Front : rendu des trois moments (horloge injectée).
7. `pnpm gates` vert.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-3, 5) - `hubSectionOrder`, repli de la checklist, section « Revivre l'event ».
- [ ] **Task 2** (AC 4, 6) - Horloge, recalcul, tests.
- [ ] **Task 3** (AC 7) - Gates, vérification réelle (événement de démo déplacé dans le temps).

## Dev Agent Record
