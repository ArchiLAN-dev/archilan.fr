# Story 46.3 - La note saute aux yeux sur la liste des inscriptions

**Status:** review
**Epic:** 46 - Notes admin (`_bmad-output/planning-artifacts/epics/epic-46-notes-admin.md`)
**Date:** 2026-10-30
**Branche:** `feature/epic-46-story-3-pastille-notes` (depuis `feature/epic-46-story-2-notes-inscription`, PR #959)
**Dépend de:** 46.1 (API), 46.2 (`authorName`).

## Story

En tant qu'admin à l'accueil,
je veux voir immédiatement quelles inscriptions ont une note ouverte et la lire sans cliquer,
afin de ne rien rater pendant les arrivées.

## Contexte

- Liste : `frontend/src/features/admin/admin-registration-dashboard.tsx` (`RegistrationList`, tableau, une ligne
  par inscription, rafraîchie par Mercure ou un polling de 30 s).
- 46.1 livrait `POST /admin/notes/open-counts` (un nombre par cible). Ça ne suffit pas pour lire la note sans clic,
  et un appel par ligne est exclu (garde-fou de l'epic).

## Décisions

1. **`open-counts` devient `open-summaries`** (46.1 n'est pas encore mergée, aucun client existant) :
   `{data: {"<id>": {count, latest: {body, author: {id, displayName}, createdAt}}}}`, la dernière note ouverte
   de chaque cible. Toujours **une seule requête SQL** (fenêtres `COUNT(*) OVER` et `ROW_NUMBER() OVER` par
   cible), toujours 500 ids au plus.
2. **Dans la cellule Participant** : pastille « N note(s) ouverte(s) » (couleur `accent-warm`), puis le texte de
   la dernière note ouverte, précédé de son auteur, sur deux lignes au plus (texte complet au survol), et une barre
   `accent-warm` à gauche de la ligne. Rien du tout pour une ligne sans note ouverte.
3. **Une requête pour toute la liste** (`["admin-notes-open", eventId, ids]`), relue au retour sur l'onglet : une
   note écrite sur la fiche apparaît en revenant à la liste. Un échec de lecture n'affiche simplement pas de
   pastille.
4. Les inscriptions annulées suivent le filtre actuel de la liste, sans traitement particulier.

## Critères d'acceptation

1. Une inscription avec au moins une note ouverte porte la pastille et le texte de sa dernière note ouverte ; une
   inscription sans note ouverte (aucune ou toutes réglées) n'a rien.
2. Le nombre de notes ouvertes de toute la liste est lu en un appel API et une requête SQL.
3. Tests API (résumés, note réglée exclue, liste trop longue, ids invalides) et front (garde, appel unique,
   pastille, seule la ligne concernée est marquée).
4. `composer gates` et `pnpm gates` verts ; vérification réelle.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - API `open-summaries`, client `fetchOpenNoteSummaries`, `OpenNotesBadge`, branchement.
- [x] **Task 2** (AC 3) - Tests.
- [x] **Task 3** (AC 4) - Gates, vérification réelle.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- API : `AdminNoteQueryInterface::openSummaries()` (type `OpenSummary`), `DbalAdminNoteQuery` en une requête à
  fenêtres, route renommée `api_admin_notes_open_summaries`. Les deux notes du test sont écrites dans la même
  seconde : c'est l'id triable dans le temps (46.1, décision 11) qui désigne la plus récente.
- Front : `OpenNoteSummary`, `isOpenNoteSummary`, `fetchOpenNoteSummaries` (`admin-notes-api.ts`),
  `OpenNotesBadge` (`open-notes-badge.tsx`), `RegistrationList` exporté pour être testé.
- Vérification réelle (Chrome, `localhost:3000`) : une note écrite sur une inscription, puis la liste de
  l'événement (50 inscriptions) : seule cette ligne porte la barre orange, « 1 note ouverte » et le texte de la
  note. Données de test supprimées ensuite.
- `composer gates` vert (3353 tests), `pnpm gates` vert (1323 tests).

### File List

- `api/src/Notes/Application/Query/AdminNoteQuery.php` (modifié)
- `api/src/Notes/Application/Query/AdminNoteQueryInterface.php` (modifié)
- `api/src/Notes/Infrastructure/Dbal/DbalAdminNoteQuery.php` (modifié)
- `api/src/Notes/Presentation/Controller/AdminNoteController.php` (modifié)
- `api/tests/Functional/AdminNoteTest.php` (modifié)
- `frontend/src/features/notes/admin-notes-api.ts` (modifié)
- `frontend/src/features/notes/open-notes-badge.tsx` (nouveau)
- `frontend/src/features/notes/admin-notes.test.tsx` (modifié)
- `frontend/src/features/admin/admin-registration-dashboard.tsx` (modifié)
