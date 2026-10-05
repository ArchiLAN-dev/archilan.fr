# Story 39.16: Contributions de tutoriel - une liste et une page par contribution

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-10-05

## Story

En tant que modérateur d'ArchiLAN,
je veux parcourir les contributions de tutoriel en liste, puis ouvrir chacune sur sa propre page,
afin de trier vite une file qui grossit et d'examiner une proposition sans faire défiler toutes les autres.

## Contexte

`/admin/moderation/contributions` (stories 31.9, 39.11, 39.12) affiche chaque contribution en entier : le tutoriel
actuel et le proposé côte à côte, toutes étapes rendues, plus les boutons. Retour de Jean (2026-10-05) : avec quelques
tutoriels de 10 à 15 étapes la page devient énorme ; il veut une liste et une page de détail (choix : page dédiée,
lien partageable, plutôt qu'un panneau latéral).

## Critères d'acceptation

1. **Liste** : une ligne compacte par contribution - le jeu (ou le nom proposé, avec « Jeu non listé »), l'auteur, la
   date, le statut, le nombre d'étapes proposées, le début du message ; la ligne mène à la contribution. Barre
   d'outils inchangée (recherche, statut, cible, tri, dans l'adresse).
2. **Page de détail** `/admin/moderation/contributions/{id}` : en-tête (cible, auteur, date, statut ; lien vers
   l'éditeur du jeu quand il est listé), message de l'auteur, décision quand elle est prise (date, raison du refus).
3. **Comparaison** étape par étape entre le tutoriel actuel et la proposition : chaque étape est « identique »,
   « modifiée », « ajoutée » ou « retirée », avec un résumé en tête (« 2 modifiées, 1 ajoutée ») ; les étapes
   identiques sont repliées, les autres montrent l'actuel et le proposé.
4. **Actions** sur une contribution en attente : Approuver (avec les pelles de l'auteur, story 41.5) et Rejeter (avec
   la raison), comme aujourd'hui ; après la décision la page montre le nouveau statut. « Retour » ramène à la liste
   avec ses filtres.
5. API : `GET /api/v1/admin/game-contributions/{id}` (404 inconnue, admin seulement) ; chaque contribution porte aussi
   `gameId`, `reviewedAt`, `rejectionReason`.
6. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 5) - API : lecture par id, champs ajoutés ; test fonctionnel.
- [x] **Task 2** (AC 1) - Liste compacte ; tests.
- [x] **Task 3** (AC 2-4) - Page de détail, comparaison (fonction pure), actions ; tests.
- [x] **Task 4** (AC 6) - Gates et vérification dans l'app.

## Dev Agent Record

### Notes

- La comparaison se fait position par position (`compareSteps`, fonction pure) contre le tutoriel **vivant** du
  jeu. Elle n'est donc montrée que pour une contribution en attente : une fois tranchée, le tutoriel a pu
  bouger depuis, et la comparer à lui serait trompeur. Une contribution tranchée (ou sur un jeu non listé)
  montre la proposition seule.
- La vue de la liste voyage dans `?liste=` de la page de détail, ce qui permet à « Contributions » d'y revenir
  avec les mêmes filtres.

### File List

- `api/src/GameSelection/Application/Query/AdminGameContributionsQueryInterface.php`
- `api/src/GameSelection/Infrastructure/Dbal/DbalAdminGameContributionsQuery.php`
- `api/src/GameSelection/Presentation/Controller/AdminGameContributionController.php`
- `api/tests/Functional/AdminGameContributionModerationTest.php`
- `frontend/src/features/admin/admin-game-contributions-api.ts` (+ test)
- `frontend/src/features/admin/contributions-moderation-panel.tsx`
- `frontend/src/features/admin/contribution-detail-page.tsx` (+ test)
- `frontend/src/app/(admin)/admin/moderation/contributions/[id]/page.tsx`
