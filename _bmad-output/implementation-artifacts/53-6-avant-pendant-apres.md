# Story 53.6 - Avant, pendant, après

**Status:** in-progress (vérification réelle à faire)
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-6-avant-pendant-apres` (empilée sur `feature/epic-53-story-5-guides-d-aide`, PR #1088)
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

- [x] **Task 1** (AC 1-3, 5) - `hubSectionOrder`, repli de la checklist, section « Revivre l'event ».
- [x] **Task 2** (AC 4, 6) - Horloge, recalcul, tests.
- [ ] **Task 3** (AC 7) - Gates, vérification réelle (événement de démo déplacé dans le temps). Gates faits ; vérification réelle à faire.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- `hubSectionOrder(phase)` (`features/registrant-hub/hub-sections.ts`, pur) : avant = checklist, infos pratiques, outils,
  liens ; pendant = outils, infos pratiques, checklist, liens ; après = « Revivre l'event », outils, liens. « Liens » est
  la paire « Mon inscription » / « Les guides » (53.5), en bas à chaque moment.
- Pendant : la checklist garde son compteur (« 4 sur 5 étapes faites ») et se replie derrière « Voir la liste ».
- Après : checklist et infos pratiques masquées ; les outils ne gardent que les sondages et la carte pelles (la lecture
  de la partie est coupée).
- « Revivre l'event » (`HubRelive`, `reliveLinks` pur) : résultats toujours, récap de ma partie (`/parties/{id}`) quand
  la session est `stopped` ou `finished`, rediffusion si `vodUrl` (nouvel onglet), photos si la page de l'événement
  montre sa galerie (2 photos ou plus ; ancre `#photos` ajoutée sur la galerie). Même clé de requête que la carte
  partie des outils.
- Moment recalculé à chaque minute (`useMinuteClock`) dans la garde et la vue : le passage avant/pendant/après se fait
  sans recharger la page.
- Pas de changement API.
- Gates front : typecheck, lint, test (257 suites, 1637 tests), build verts.

### File List

- `frontend/src/features/registrant-hub/hub-sections.ts` (nouveau)
- `frontend/src/features/registrant-hub/hub-sections.test.tsx` (nouveau)
- `frontend/src/features/registrant-hub/hub-tools.tsx` (modifié)
- `frontend/src/features/registrant-hub/registrant-hub.tsx` (modifié)
- `frontend/src/features/registrant-hub/registrant-checklist.tsx` (modifié)
- `frontend/src/app/(public)/evenements/[eventSlug]/mon-event/page.tsx` (modifié)
- `frontend/src/app/(public)/evenements/[eventSlug]/page.tsx` (modifié)

