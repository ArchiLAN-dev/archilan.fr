# Story 11.6: Repère visuel des notes internes dans la liste des jeux

**Status:** review
**Epic:** 11 - Bibliothèque de jeux admin
**Date:** 2026-10-02

## Story

En tant qu'admin qui parcourt la bibliothèque de jeux,
je veux voir d'un coup d'œil quels jeux ont une note interne, et pouvoir la survoler,
afin de ne pas passer à côté d'une consigne laissée par l'équipe sans ouvrir chaque jeu.

## Contexte

Demande de Jean (2026-10-02). Les notes internes d'un jeu (`admin_notes`, story 3.12, onglet « Notes » de l'éditeur)
ne sont visibles qu'en ouvrant le jeu ; la liste `/admin/jeux` n'en dit rien.

## Critères d'acceptation

1. `GET /api/v1/admin/games` renvoie pour chaque jeu `hasAdminNotes` (vrai si la note n'est pas vide une fois les
   espaces retirés) et `adminNotesExcerpt` (les 200 premiers caractères, ou `null`).
2. Dans la liste `/admin/jeux` (tableau et vue mobile), un jeu avec une note porte une icône de note à côté de son
   nom ; le survol montre l'extrait ; l'icône a un libellé accessible (« Note interne »).
3. Rien ne change hors de l'admin : la note reste interne (story 3.12).
4. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `DbalAdminGameListQuery` : champs dérivés de `admin_notes` ; test fonctionnel.
- [x] **Task 2** (AC 2) - Front : type `AdminGame`, icône dans les deux vues ; test.
- [x] **Task 3** (AC 4) - Gates.

## Dev Agent Record

| Test | Rouge avant | Vert après |
|------|-------------|------------|
| `AdminGameLibraryTest::testTheListFlagsGamesWithAnInternalNote` (note longue tronquée à 200, note blanche = pas de note, sans note) | champs absents | `DbalAdminGameListQuery` |
| `admin-note-marker.test.tsx` (rien sans note, icône libellée et survol) | composant absent | `AdminNoteMarker` |

Icône `StickyNote` (couleur `accent-warm`) après le badge « Désactivé », dans le tableau et dans la vue mobile ; le
survol montre « Note interne : » suivi des 200 premiers caractères. Gates : `composer gates` (2572 tests),
`pnpm gates` (694 tests, build) verts.
