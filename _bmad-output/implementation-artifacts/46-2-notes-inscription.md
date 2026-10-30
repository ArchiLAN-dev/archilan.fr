# Story 46.2 - Notes sur une inscription (admin)

**Status:** review
**Epic:** 46 - Notes admin (`_bmad-output/planning-artifacts/epics/epic-46-notes-admin.md`)
**Date:** 2026-10-30
**Branche:** `feature/epic-46-story-2-notes-inscription` (depuis `feature/epic-46-story-1-socle-notes`, PR #958)
**Dépend de:** 46.1 (API des notes).

## Story

En tant qu'admin,
je veux voir et écrire les notes d'une inscription depuis sa fiche admin,
afin d'avoir au même endroit tout ce qu'on a remarqué sur elle et où en est chaque point.

## Contexte

- Fiche admin : `frontend/src/app/(admin)/admin/evenements/[eventId]/inscriptions/[registrationId]/page.tsx` →
  `frontend/src/features/admin/admin-registration-detail.tsx` (`DetailBody` : participant, paiement, « Envoyer un
  message », jeux). L'admin est sous `QueryProvider` (`components/admin-shell.tsx`) : TanStack Query disponible.
- **Piège de confusion** : le bloc « Envoyer un message » écrit **au membre**. Les notes sont internes : le panneau
  doit le dire clairement pour qu'on ne se trompe pas de champ.
- API (46.1) : `GET /admin/notes?targetType&targetId`, `POST /admin/notes`, `PATCH /admin/notes/{id}`,
  `POST .../follow-ups`, `POST .../settle`, `POST .../reopen`, `GET .../revisions`. Erreurs `{error: {code,
  message}}` avec un message français.
- Modèle d'un client API admin : `frontend/src/features/content/partners-api.ts` (`dataOf`, `send`,
  `errorMessage`, gardes `is*`, `SaveResult`).

## Décisions

1. **Module `features/notes/`** : `admin-notes-api.ts` (types, gardes, fonctions) et `admin-notes-panel.tsx`
   (`AdminNotesPanel({ targetType, targetId })`). Le panneau ne sait rien d'une inscription : la cible suivante
   (compte utilisateur) le réutilisera tel quel.
2. **Placement** : juste après la carte du participant, avant le paiement, pour que la note se voie en ouvrant la
   fiche. Titre « Notes internes », sous-titre « Visibles des admins uniquement, jamais du membre. »
3. **Chronologie** : notes ouvertes d'abord (ordre de l'API). Une note réglée est repliée (texte sur une ligne,
   grisé, « Réglée ») et se déplie au clic. Dans une note : texte, auteur et date, mention « modifiée » si elle a un
   historique, puis les suivis (« Léa : commentaire », « Jean a réglé la note : texte », « Léa a rouvert la note »).
4. **Actions** par note : Suivre (champ de commentaire), Régler / Rouvrir (texte facultatif, même champ), Modifier
   (le texte de la note, en place), Historique (liste des versions précédentes, chargée à la demande).
5. **Textes en texte brut** (`whitespace-pre-wrap`), jamais interprétés en HTML.
6. Après chaque écriture : invalidation de la requête des notes de la cible ; erreur de l'API affichée sous le
   champ concerné.

## Critères d'acceptation

1. Le panneau « Notes internes » apparaît sur la fiche admin d'une inscription, avec la mention de
   confidentialité, un état vide (« Aucune note. ») et un formulaire « Nouvelle note » (compteur 2000).
2. Une note écrite apparaît en tête, ouverte, avec son auteur et sa date.
3. Suivre, Régler, Rouvrir et Modifier fonctionnent et rafraîchissent la chronologie ; l'historique liste les
   versions précédentes (texte, auteur, date).
4. Une note réglée est repliée et grisée ; elle se déplie au clic.
5. Les erreurs de l'API s'affichent ; aucune exception non gérée ; lecture en échec = message « Les notes n'ont pas
   pu être chargées. »
6. Tests front : gardes de type (note complète acceptée, objet incomplet refusé), fonctions API via msw (lecture,
   écriture 201, refus 422 avec message), rendu statique (état vide, note ouverte, note réglée repliée, libellés
   des suivis).
7. `pnpm gates` vert ; vérification réelle dans le navigateur.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-5) - `admin-notes-api.ts` et `admin-notes-panel.tsx`, branchement dans `DetailBody`.
- [x] **Task 2** (AC 6) - Tests.
- [x] **Task 3** (AC 7) - Gates, vérification réelle, Dev Agent Record.

## Dev Notes

- `frontend/AGENTS.md` : gardes `is*` dans le fichier API (AC-TS3/4), aucune exception remontée (AC-API2),
  `staleTime` explicite (AC-API5), clés de liste = ids (AC-KEY1), jetons de couleur (AC-CSS2).
- Hors périmètre : la pastille sur la liste des inscriptions (46.3).

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- `features/notes/admin-notes-api.ts` : types, gardes (`isAdminNote`, `isNoteFollowUp`, `isNoteRevision`),
  `fetchAdminNotes`, `fetchNoteRevisions`, `writeAdminNote`, `editAdminNote`, `followUpAdminNote`, `turnAdminNote`.
- `features/notes/admin-notes-panel.tsx` : `AdminNotesPanel`, `NoteList`, `NoteCard` (repliée si réglée, actions
  Suivre / Régler ou Rouvrir / Modifier / Historique), `RevisionList` chargée à la demande, `TextForm` commun.
- Branché dans `DetailBody` juste après la carte du participant.
- En passant sur l'API de 46.1 : l'historique renvoie aussi l'`id` de chaque révision (clé de liste stable,
  AC-KEY1) ; test fonctionnel complété.
- Vérification réelle (Chrome, `localhost:3000`, compte de Jean) : écrire, modifier (mention « modifiée »), régler
  avec texte (suivi « ... a réglé la note »), historique (version précédente barrée), rechargement (note réglée
  repliée sur une ligne). Données de test supprimées ensuite de la base locale.
- Constat : en local, la chronologie se met à jour 2 à 3 s après le clic (l'API de dev est lente) ; le bouton reste
  désactivé pendant ce temps, pas de double envoi possible.
- `pnpm gates` vert (1320 tests) et `composer gates` vert (3353 tests).

### File List

- `frontend/src/features/notes/admin-notes-api.ts` (nouveau)
- `frontend/src/features/notes/admin-notes-panel.tsx` (nouveau)
- `frontend/src/features/notes/admin-notes.test.tsx` (nouveau)
- `frontend/src/features/admin/admin-registration-detail.tsx` (modifié)
- `api/src/Notes/Application/Query/AdminNoteQueryInterface.php` (modifié : `id` de révision)
- `api/src/Notes/Infrastructure/Dbal/DbalAdminNoteQuery.php` (modifié : `id` de révision)
- `api/tests/Functional/AdminNoteTest.php` (modifié)
