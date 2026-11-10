# Story 3.18 - Barre d'événement au lieu de la colonne de gauche (admin)

**Status:** review
**Epic:** 3 - Event Lifecycle Backoffice (`_bmad-output/planning-artifacts/epics/epic-3-event-lifecycle-backoffice.md`)
**Date:** 2026-11-10
**Branche:** `feature/epic-3-story-18-barre-evenement` (depuis `develop`)
**Dépend de:** 3.17 (hub, releasée en v0.62.0). Front seul, aucune route API nouvelle.

## Story

En tant qu'orga,
je veux que les sections d'un événement soient en haut de la page, avec son nom et son statut,
afin que le contenu garde toute la largeur et que la navigation fasse partie de la page.

## Contexte

Retour de Jean le 2026-11-10 après la v0.62.0 : « le menu à gauche est trop simple, il fait tache et prend une colonne
de plus ». La navigation de 3.17 (`features/admin/event-hub/admin-event-nav.tsx`) est une colonne de 13rem posée par
`app/(admin)/admin/evenements/[eventId]/layout.tsx` : liens texte sans icône, titres de groupe en capitales, et une
colonne qui réduit la frise des présences et les tableaux d'inscriptions.

## Décisions

1. La colonne disparaît : une **barre d'événement** en haut de toutes les pages de l'événement, sur toute la largeur.
   - Ligne 1 : lien « Événements », nom de l'événement, pastille de statut, dates et lieu en petit.
   - Ligne 2 : onglets avec icône (mêmes icônes que les cartes du hub), **du même style que les onglets du reste du
     site** (pelles, classement, run perso, sondage ; demande de Jean) : texte en gras, soulignement accent sur
     l'actif, sans séparateurs. Le style est partagé dans `components/ui/tabs.ts` (`TAB_LIST_CLASS`, `tabClassName`).
   - Défilement horizontal sur mobile, la barre reste collée en haut au défilement (fond opaque).
2. La vue d'ensemble ne répète plus le nom ni les dates : la barre les porte. Elle garde les transitions de statut
   et les cartes de sections.
3. Les sous-pages ne changent pas (leurs titres restent ; c'est hors périmètre).

## Critères d'acceptation

1. Aucune page sous `/admin/evenements/{id}` n'a de colonne de navigation ; le contenu prend toute la largeur.
2. La barre montre le nom, le statut, les dates et le lieu, et un lien vers la liste des événements.
3. Chaque section est un onglet avec icône ; l'onglet courant porte `aria-current="page"` et un soulignement.
4. Les onglets ont le style de ceux du reste du site ; l'ordre et les sections sont ceux de 3.17.
5. Sur mobile, les onglets défilent horizontalement sans faire déborder la page.
6. La vue d'ensemble n'affiche plus de second titre de l'événement.
7. Tests : rendu de la barre (titre, statut, onglet courant, séparateurs), vue d'ensemble sans h1 en double.
8. `pnpm gates` vert.

## Tâches

- [x] `admin-event-nav.tsx` : barre horizontale (icônes, soulignement, séparateurs, en-tête événement).
- [x] `layout.tsx` : plus de grille à deux colonnes.
- [x] `admin-event-hub.tsx` : retirer le h1 et la ligne de dates, garder les transitions.
- [x] Tests, gates.

## Dev Agent Record

- `admin-event-nav.tsx` rend deux frères : l'en-tête (lien Événements, nom, statut, mise en avant, dates, lieu,
  visibilité ; squelette pendant le chargement) et la barre d'onglets collante (`top-14` sous l'en-tête mobile de
  l'admin, `top-0` à partir de `md`), une icône par onglet, style d'onglet commun `components/ui/tabs.ts`.
- `layout.tsx` : plus de grille à deux colonnes. La vue d'ensemble perd son h1 au nom de l'événement (titre
  « Vue d'ensemble » à côté des transitions).
- Vérifié en local sur 127.0.0.1 (démo 4.23) en desktop : barre pleine largeur, onglet courant souligné.
- Tests : `event-hub.test.tsx` (+2, 1 adapté). typecheck, lint, tests admin verts.
