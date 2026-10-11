# Story 32.22: L'historique d'un profil ne montre que les parties publiques

**Status:** review
**Epic:** 32 - Récaps de partie
**Date:** 2026-10-09

## Story

En tant que joueur d'une partie privée,
je veux que ma partie n'apparaisse sur mon profil public que si j'en ai rendu le récap public,
afin qu'une partie privée reste privée : ni son titre, ni son jeu, ni ses checks.

## Contexte

L'historique des runs d'un profil (`/joueurs/{slug}`, `GET /api/v1/players/{slug}/history`) liste toutes les
parties privées terminées du joueur. Depuis la 32.20, seul le lien vers le récap dépend de sa visibilité : le
titre, le jeu, les checks et le goal d'une partie privée restaient visibles de tous. Jean (2026-10-08) : il
faut uniquement celles qui ont rendu public le récap.

## Critères d'acceptation

1. **Visiteur** (anonyme ou autre membre) : l'historique contient les parties privées au récap public
   (`run.recap_public`), les sessions d'événements publics et les hebdos terminées (rien de privé). Une partie
   privée non publiée et une session d'événement non public n'y figurent pas, même pour un participant.
2. **Le joueur lui-même** voit tout son historique ; chaque ligne qu'un visiteur ne voit pas porte `isPrivate:
   true` et un badge « Privée ».
3. **Pagination juste** : le filtre s'applique avant la pagination ; `meta.total` compte ce que le
   lecteur voit.
4. **Vitrine** : « Meilleures runs » et « Jeux les plus joués » se calculent sur l'historique public (rendu
   côté serveur, anonyme), donc sans partie privée, y compris pour le joueur lui-même.
5. **Front** : la page reste rendue côté serveur (anonyme) ; quand le membre connecté regarde son propre profil,
   l'historique est relu côté client avec ses cookies pour y ajouter ses parties privées.
6. Inchangé : le compteur « Runs » des statistiques du profil.
7. Gates verts ; tests (visiteur, propriétaire, total paginé, front).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 3) - `DbalPlayerHistoryQuery` expose `is_public` ; `PlayerHistoryQuery` filtre avant
  pagination et pose `isPrivate` ; tests fonctionnels.
- [x] **Task 2** (AC 2, 4, 5) - Historique en composant client (`PlayerRunHistory`), relu pour le propriétaire ;
  badge « Privée » ; tests.
- [x] **Task 3** (AC 7) - Gates.

## Dev Agent Record

### Notes

- Règle volontairement plus stricte que `SessionRecapAudience` (qui ouvre aussi le récap aux participants) : le
  profil est une vitrine publique, un participant ne la voit pas différemment d'un visiteur. Cacher plus ne
  peut pas faire fuiter une partie privée.
- Un co-joueur n'apparaît toujours que dans l'historique du propriétaire du slot (hors périmètre).

### File List

- `api/src/Identity/Infrastructure/Dbal/DbalPlayerHistoryQuery.php`
- `api/src/Identity/Application/Query/PlayerHistoryQuery.php`
- `api/tests/Functional/PlayerProfileTest.php`
- `frontend/src/features/players/player-profile-api.ts`
- `frontend/src/features/players/player-run-history.tsx` (+ test)
- `frontend/src/features/players/player-profile-page.tsx`
