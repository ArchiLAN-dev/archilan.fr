# Story 16.22: « Mes parties » dans le menu du compte, parties en cours en tête

**Status:** review
**Epic:** 16 - Personal Runs - Private User-Created Archipelago Games
**Date:** 2026-10-08

## Story

En tant que membre connecté d'ArchiLAN,
je veux atteindre « Mes parties » directement depuis le menu de mon avatar,
afin de retrouver mes parties privées sans passer par « Mon espace »,
et y voir d'abord celles qui sont en cours, que je les aie créées ou rejointes.

## Contexte

Le menu de l'avatar (en-tête, `UserMenu`) regroupe les actions du compte : Mon profil, Mon espace, Mon portefeuille,
Administration, Se déconnecter. « Mes parties » (`/compte/parties`, story 30.36) n'y figure pas : il faut ouvrir
« Mon espace » puis choisir l'onglet. Demande de Jean (2026-10-08) : ajouter l'entrée au menu, en première
position.

Le menu mobile (`AuthNavMobile` dans `public-shell.tsx`) reprend les mêmes entrées en boutons empilés et doit suivre.

Sur la page, les parties créées sont classées par statut (en cours, en pause, brouillons, terminées), mais les
parties rejointes (story 16.10) forment une section à part, tout en bas, même quand elles sont en cours. Demande de
Jean (2026-10-08) : les parties créées et rejointes en cours doivent venir en premier.

## Critères d'acceptation

1. Le menu de l'avatar affiche « Mes parties » (icône manette) qui mène à `/compte/parties`, en première
   position (avant « Mon profil »). Le menu se ferme au clic, comme pour les autres entrées.
2. Le menu mobile affiche la même entrée, en première position.
3. Un test vérifie la présence du lien, sa cible et son rang dans le menu de l'avatar.
4. Sur `/compte/parties`, les parties rejointes rejoignent les groupes de statut des parties créées (même ordre :
   en cours d'abord) au lieu d'une section « Parties rejointes » en bas. Leur carte porte un repère « Rejointe ».
   « Reprendre » reste réservé aux parties dont on est propriétaire. Annulées et archivées : inchangé.

## Tâches

- [x] **Task 1** (AC 1, 3) - `user-menu.tsx` : liens du panneau extraits dans `AccountMenuLinks` (exporté, rendu
  statique testable), entrée « Mes parties » ajoutée.
- [x] **Task 2** (AC 2) - `public-shell.tsx` : entrée « Mes parties » dans `AuthNavMobile`.
- [x] **Task 3** (AC 3) - `user-menu.test.tsx`.
- [x] **Task 3b** (AC 4) - `personal-runs-list-page.tsx` (groupes communs), `personal-run-card.tsx` (repère
  « Rejointe »), tests `personal-runs-list-page.test.tsx` et `personal-run-card.test.tsx`.
- [x] **Task 4** - `pnpm gates` vert, vérification visuelle dans l'app.

## Dev Notes

- Le panneau n'est rendu qu'une fois ouvert (état local) : le projet n'a pas de test d'interaction (pas de
  Testing Library), d'où l'extraction des liens dans un composant pur rendu avec `renderToStaticMarkup`.
- Aucun changement d'API ni de route : la page `/compte/parties` existe déjà, et l'API sépare toujours
  `owned` / `joined` ; seul l'affichage fusionne.

## Dev Agent Record

### File List

- `frontend/src/features/auth/user-menu.tsx`
- `frontend/src/features/auth/user-menu.test.tsx`
- `frontend/src/components/public-shell.tsx`
- `frontend/src/features/personal-runs/personal-runs-list-page.tsx`
- `frontend/src/features/personal-runs/personal-runs-list-page.test.tsx`
- `frontend/src/features/personal-runs/personal-run-card.tsx`
- `frontend/src/features/personal-runs/personal-run-card.test.tsx`
