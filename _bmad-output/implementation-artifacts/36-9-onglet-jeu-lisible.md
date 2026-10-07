# Story 36.9: Un onglet « Jeu et pelles » qu'on lit d'un coup d'œil

**Status:** review
**Epic:** 36 - Fiche utilisateur admin
**Date:** 2026-10-05

## Story

En tant qu'admin d'ArchiLAN,
je veux voir d'un coup d'œil les runs d'un membre et ses parties passées,
afin de repérer ce qui tourne encore et ce qu'il a joué, sans dérouler deux longues listes.

## Contexte

Retour de Jean (2026-10-05) sur l'onglet « Jeu et pelles » de la 36.8 : la liste des parties est indigeste. Constat
sur une fiche réelle : la section Jeu fait 2 700 px.

- **Runs personnelles** (21) : une carte par run qui empile titre, statut, bouton. Les statuts s'affichent en anglais
  (`idle`, `completed`) : le front traduit d'anciens noms (`running`, `paused`, `finished`) alors que la run expose
  `draft`, `starting`, `active`, `stopping`, `idle`, `restarting`, `completed`, `cancelled`. « Arrêter la partie »
  s'affiche sur toute run qui a une session, terminée comprise (l'API répond alors « pas de partie en cours »).
- **Parties terminées** (15 lignes) : une ligne par jeu, alors qu'il n'y a que 6 parties ; « test, 27 juillet »
  revient 7 fois.

Même retour : dans l'onglet, Pelles passe avant Jeu (fait dans cette branche, voir la 36.8).

## Critères d'acceptation

1. Runs et parties dans une seule liste « Runs et parties » (retour de Jean : fusionner avec un filtrage). Une run
   personnelle terminée et sa partie de l'historique (même session) ne font qu'une ligne : le lien de la run, la date
   et les jeux de la partie. Une partie sans run (événement, hebdo) reste seule, sans lien.
2. Une ligne par élément : titre, « Invité » pour une run rejointe, date de fin, statut traduit en pastille colorée,
   jeux joués en étiquettes. Les runs vivantes d'abord, puis les brouillons, puis les terminées les plus récentes.
3. Filtres Tout / En cours / Brouillons / Terminées, avec leur nombre ; un filtre vide est désactivé ; changer de
   filtre revient à la première page.
4. Pagination par 5 (retour de Jean : une pagination plutôt qu'un « afficher plus »), masquée quand tout tient sur
   une page ; une page au-delà de la fin retombe sur la dernière.
5. « Arrêter » ne s'affiche que sur une run dont le membre est propriétaire, qui a une session, et dont le statut est
   vivant (`starting`, `active`, `idle`, `restarting`).
6. Une run pas encore terminée (brouillon, en veille, en cours) montre les jeux que le membre y a choisis, dans
   l'ordre des slots (retour de Jean) ; une run terminée montre les jeux joués de sa partie. La lecture admin
   `GET /api/v1/admin/users/{id}/gaming` gagne `games` sur chaque run ; aucune autre route ne change.
7. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 2, 5) - Statuts de run (libellé, ton, vivant), tri, règle d'arrêt ; tests.
- [x] **Task 2** (AC 1-4) - Regroupement des parties par session, fusion avec les runs, filtres, pagination
  (`SheetPager`, `sheetPage` partagés par la fiche) ; tests.
- [x] **Task 3** (AC 6) - API : `RunParticipantRepositoryInterface::findByUserId`,
  `PersonalRunGameSelection::gamesByRunForMember` (2 requêtes quel que soit le nombre de runs), `games` dans
  `AdminUserGamingQuery` ; test fonctionnel. Front : type, garde, affichage.
- [x] **Task 4** (AC 7) - Gates et vérification sur la fiche réelle.

## Notes techniques

- `admin-user-gaming.tsx` : `runStatus`, `orderRuns`, `canStopRun`, `groupHistory` (par `sessionId`, jeux
  dédoublonnés), `buildGameRows` (rapprochement run / partie par session), `gameFilterOf`, `GameList`.
- `admin-sheet-section.tsx` : `SHEET_PAGE_SIZE`, `sheetPage`, `SheetPager`, réutilisables par les autres listes de la
  fiche.
- L'API accepte toujours un arrêt sur toute run qui a une session (elle répond `run_not_running` sinon) : seul le
  bouton est restreint, côté front.
- Fiche réelle vérifiée (21 runs, 15 lignes d'historique) : les 6 parties correspondent toutes à des runs terminées,
  21 lignes au total (9 en cours, 5 brouillons, 7 terminées) ; la section Jeu passe de 2 724 px à 691 px.
