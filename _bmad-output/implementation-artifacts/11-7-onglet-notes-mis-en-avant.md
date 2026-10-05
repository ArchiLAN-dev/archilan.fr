# Story 11.7: Les onglets de l'éditeur de jeu qui attendent l'admin sont mis en avant

**Status:** review
**Epic:** 11 - Bibliothèque de jeux admin
**Date:** 2026-10-05

## Story

En tant qu'admin qui ouvre un jeu dans l'éditeur,
je veux voir tout de suite ce qui m'y attend - une note interne, un apworld à vérifier, une proposition de tutoriel,
afin de ne pas modifier le jeu sans avoir vu ce que l'équipe ou les membres ont laissé.

## Contexte

La 11.6 a ajouté un repère de note dans la **liste** `/admin/jeux`. Retour de Jean (2026-10-05) : ce n'était pas la
demande - c'est sur la **page du jeu** (`/admin/jeux/{id}`) que l'onglet « Notes » doit ressortir. Puis : « même chose
pour APWorld et Tutoriel » ; pour le tutoriel, une proposition de modification ou de création (contribution des
membres, story 31.x) doit se voir sur cette page. Aujourd'hui les cinq onglets (Général, Catalogue, APWorld,
Tutoriel, Notes) sont identiques quoi qu'il arrive, et les propositions de tutoriel ne se voient qu'en modération.

## Critères d'acceptation

1. Un onglet qui attend l'admin se distingue des autres même quand il n'est pas actif : couleur chaude, son icône,
   une pastille (ou le nombre quand il y en a plusieurs) ; son libellé accessible dit pourquoi.
   - **Notes** : le jeu a une note (non vide une fois les espaces retirés).
   - **APWorld** : un incident ouvert, une nouvelle version qui attend la validation de l'admin (`awaiting`), ou un
     test de génération en échec sans dérogation.
   - **Tutoriel** : au moins une proposition de tutoriel en attente pour ce jeu.
2. Sans rien en attente, un onglet reste comme les autres.
3. L'onglet Tutoriel liste les propositions en attente (auteur, date, nombre d'étapes, message) et mène à la file de
   modération ouverte sur ce jeu ; il rappelle qu'une proposition approuvée remplace le tutoriel.
4. `GET /api/v1/admin/game-contributions` accepte `game` (id du jeu) pour ne lister que ses contributions.
5. Les repères suivent l'état : note enregistrée ou vidée, proposition approuvée ou refusée en modération (même
   préfixe de cache), incidents et version candidate rafraîchis.
6. Tests et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-2, 5) - `editorTabFlags`, `apworldNeedsAttention`, `hasAdminNote`, `EditorTabButton` ; tests.
- [x] **Task 2** (AC 3-4) - Filtre `game` de la file des contributions (API + front), `PendingTutorialProposals` ;
  tests.
- [x] **Task 3** (AC 6) - Gates et vérification dans l'app.

## Notes techniques

- `admin-game-editor.tsx` : `EditorTabButton` reçoit un `TabFlag` {icon, hint, count?} ; non actif : fond
  `accent-warm/10`, texte `accent-warm` ; actif : soulignement `accent-warm`. `aria-label` « {onglet} - {pourquoi} ».
- Propositions du jeu : requête `["admin-game-contributions", "game", id]`, même préfixe que la modération
  (`contributions-moderation-panel`) : une approbation là-bas rafraîchit l'onglet ici. Lien « Examiner dans la
  modération » : `/admin/moderation/contributions?q={nom du jeu}`.
- API : `ContributionQueryFilters::$gameId`, paramètre `game`, `c.game_id = :gameId` ; cas ajouté à
  `AdminGameContributionModerationTest`.
- Vérifié dans l'app sur un jeu avec note (onglet Notes en évidence) ; la base de dev n'a ni proposition en attente
  ni apworld à vérifier : ces deux cas sont couverts par les tests.
- Retour de Jean (2026-10-05) : le repère de note de la **liste** `/admin/jeux` (story 11.6) est retiré - la page du
  jeu suffit. Partent avec lui : `AdminNoteMarker` et son test, `hasAdminNotes` / `adminNotesExcerpt` du type front et de
  `DbalAdminGameListQuery` (et `g.admin_notes` de son SELECT, ajouté pour ce seul usage), le test fonctionnel associé.
  La note elle-même ne change pas.
