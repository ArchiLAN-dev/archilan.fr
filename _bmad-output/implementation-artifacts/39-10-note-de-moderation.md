# Story 39.10: Note de modération, sans prévenir le membre

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-28

## Story

En tant que membre du staff d'ArchiLAN,
je veux consigner une note sur un membre (« déjà rappelé à l'ordre en vocal », « comportement limite, à
surveiller ») sans le sanctionner ni le prévenir,
afin de garder une trace dans son dossier et dans le forum staff, partagée entre nous.

## Contexte

Aujourd'hui la plus légère des actions est l'avertissement : il notifie toujours le membre sur le site, et lui
envoie un MP si la synchronisation Discord est active. Il n'existe aucun moyen de consigner quelque chose sans
que le membre le sache.

## Critères d'acceptation

1. **Nouvelle action « Note interne »** sur la fiche admin, avec un texte obligatoire. Elle est enregistrée dans
   l'historique de la fiche (acteur, date, texte) comme les autres actions.
2. **Le membre n'en sait rien** : ni notification sur le site, ni MP du bot, aucun effet sur son accès, sur
   Discord ni sur ses stats. Une note seule n'ouvre pas au membre la section « Contacter la modération », et le
   staff ne peut pas lui « répondre » sur la seule base d'une note.
3. **Dossier et forum** : la note ouvre le dossier du membre s'il n'en a pas (sans rouvrir un dossier clos) et
   est postée dans son post du forum staff (étiquette « Note » si elle existe, sinon le post garde la sienne),
   marquée comme interne.
4. **Fiche admin** : la ligne de la note indique « Note interne : le membre n'est pas prévenu » ; aucune issue
   Discord « en cours » n'y est attendue.
5. Mêmes garde-fous que les autres actions : réservée aux admins, jamais sur un admin ni sur soi-même.
6. `composer gates` et `pnpm gates` passent.

## Mise en place côté Discord

- Créer l'étiquette « Note » dans le forum staff (facultatif : sans elle, le post garde son étiquette).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 5) - Action `note` dans `AccountModerationService` et sa route.
- [x] **Task 2** (AC 2, 3) - Job de la sanction : pas de MP, pas de réouverture ; message du forum.
- [x] **Task 3** (AC 2) - « Sanctionné » = une action autre qu'une note (contact du membre, réponse du staff).
- [x] **Task 4** (AC 4) - Front : action, libellé, ligne de l'historique.
- [x] **Task 5** (AC 6) - Gates.

## Dev Agent Record

- **Domaine** : `ModerationAction::ACTION_NOTE` et `isSanction()` (toute action sauf une note) ;
  `ModerationCaseMessage::DM_INTERNAL`. Pas de migration (l'action est une valeur de la colonne existante).
- **Service** `AccountModerationService::note()` : texte obligatoire, mêmes garde-fous (admin, soi-même, compte
  existant), action enregistrée puis job du forum ; aucun `Notifier`. Route
  `POST /api/v1/admin/community/accounts/{userId}/note`.
- **Job de la sanction** : une note enregistre `internal` comme issue du MP sans rien envoyer, n'agit pas sur le
  serveur, ne rouvre pas un dossier clos (elle en ouvre un s'il n'y en a pas). Forum : titre et étiquette
  « Note », champ « Visibilité : interne : le membre n'est pas prévenu » à la place du MP.
- **« Sanctionné »** = au moins une action autre qu'une note, dans `ContactModeration`, `ReplyToMember` et
  `MemberModerationContactQuery` : une note seule n'ouvre pas la section « Contacter la modération » et ne permet
  pas de répondre au membre.
- **Front** : option « Note interne » (avec rappel « visible par le staff seulement »), libellé « Note (obligatoire) »,
  ligne d'historique « Note interne : le membre n'est pas prévenu ».

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `AccountModerationServiceTest`, `ModerationActionDirectMessageTest`, `PostModerationActionToForumHandlerTest`, `ContactModerationTest`, `ReplyToMemberTest`, `ModerationNoteTest` (fonctionnel) | 6 erreurs, 2 échecs | verts |
| `admin-user-moderation-case.test.ts` (route, pas d'issue Discord attendue) | type `ModerationCommand` | verts |

Gates : `composer gates` (2460 tests), `pnpm gates` (581 tests, build) verts.
