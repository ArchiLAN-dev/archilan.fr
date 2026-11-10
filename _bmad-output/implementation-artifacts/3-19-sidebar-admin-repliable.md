# Story 3.19 - Barre latérale de l'admin repliable

**Status:** review
**Epic:** 3 - Event Lifecycle Backoffice (`_bmad-output/planning-artifacts/epics/epic-3-event-lifecycle-backoffice.md`)
**Date:** 2026-11-10
**Branche:** `feature/epic-3-story-19-sidebar-admin-repliable` (depuis `feature/epic-51-story-6-resultats-sondage`)
**Dépend de:** rien côté code ; empilée pour que Jean voie tout en local. Front seul.

## Story

En tant qu'admin,
je veux pouvoir réduire la barre latérale de l'administration à ses icônes,
afin de rendre la largeur aux pages qui en ont besoin (grille des présences, tableaux, résultats de sondage).

## Contexte

Retour de Jean le 2026-11-10 : « on arrive à un moment où la sidebar de navigation admin doit être minimisée ».
`components/admin-shell.tsx` a déjà deux barres : la complète (`w-60`, à partir de `lg`) et un rail d'icônes (`w-12`,
de `md` à `lg`, `aria-hidden`).

## Décisions

1. Un bouton « Réduire le menu » en haut de la barre complète ; sur grand écran, la barre laisse place au rail
   d'icônes existant, qui porte un bouton « Agrandir le menu ».
2. Le choix est retenu dans le navigateur (`localStorage`, clé `archilan.admin.sidebar`), lu au premier rendu ; s'il
   n'est pas lisible, la barre est complète.
3. Le rail devient une vraie navigation (libellé accessible, plus `aria-hidden`), le nom de chaque section au survol
   (déjà en `title`), les badges en pastille comme avant.
4. Tablette et mobile inchangés.

## Critères d'acceptation

1. Sur grand écran, « Réduire le menu » passe au rail de 48 px et le contenu reprend la largeur ; « Agrandir le
   menu » revient à la barre complète.
2. Le choix survit à une navigation et à un rechargement.
3. Le rail est lisible par un lecteur d'écran (nav nommée, liens avec `aria-label`).
4. Tests : bascule, mémorisation, rail accessible. `pnpm gates` vert.

## Tâches

- [x] État replié mémorisé.
- [x] Bouton réduire / agrandir, classes conditionnelles des deux barres et du contenu.
- [x] Rail accessible.
- [x] Tests, gates.

## Dev Agent Record

- `components/admin-shell.tsx` : `readSidebarCollapsed` / `writeSidebarCollapsed` (try/catch, stockage absent ou
  bloqué = barre complète), `sidebarLayout(collapsed)` pour les classes des deux barres et du contenu ; bouton
  `PanelLeftClose` dans l'en-tête de la barre complète, `PanelLeftOpen` dans le rail (visible à partir de `lg`).
- Le rail perd `aria-hidden` : `aria-label` sur l'aside, la nav, chaque lien (avec le nombre en attente quand il y a
  une pastille), `aria-current` sur la page active.
- `main` prend `min-w-0` pour que les tableaux larges défilent dans le contenu.
- Tests : `components/admin-shell.test.ts` (5). typecheck, lint, test (1537), build verts.
