# Story 46.1 - Socle des notes (API)

**Status:** ready-for-dev
**Epic:** 46 - Notes admin (`_bmad-output/planning-artifacts/epics/epic-46-notes-admin.md`)
**Date:** 2026-10-30
**Branche:** `feature/epic-46-story-1-socle-notes` (depuis `develop`)
**Dépend de:** rien. Prérequis de 46.2 et 46.3.
**Origine:** brainstorming du 2026-10-30 (`_bmad-output/brainstorming/brainstorming-session-2026-10-30-2145.md`).

## Epic 46 en bref

Les admins (les bénévoles) veulent noter ce qu'ils remarquent sur une inscription pour ne pas perdre l'information,
et que l'accueil le voie le jour J.

- **46.1 (cette story)** : le socle, modèle et API admin.
- 46.2 : panneau de notes sur la fiche admin d'une inscription.
- 46.3 : pastille des notes ouvertes sur la liste des inscriptions d'un événement.

## Story

En tant qu'admin,
je veux écrire une note sur une inscription, la compléter par des suivis, la modifier, la régler et la rouvrir,
afin de ne plus perdre ce que l'on remarque et de savoir où en est chaque point.

## Contexte (cartographie du 2026-10-30)

Rien n'existe pour des notes internes. `RegistrationAdminMessage` / `SendMessageToRegistrant`
(`api/src/Registrations/...`) sont des messages **envoyés au membre** : ne pas les réutiliser ni les étendre.

Repères :

- Inscription : `api/src/Registrations/Domain/Entity/Registration.php` (id `string` 32), dépôt
  `RegistrationRepositoryInterface::findById()`. Routes admin existantes :
  `api/src/Registrations/Presentation/Controller/AdminRegistrationController.php`
  (`/api/v1/admin/events/{eventId}/registrations[/{registrationId}]`).
- Forme d'une commande admin à copier : `api/src/Content/Application/Command/ManagePartners.php` (table
  `MESSAGES` code → message, `invalid()` → `ValidationException`, `get()` → `NotFoundException`, id
  `bin2hex(random_bytes(16))`, horloge `Psr\Clock\ClockInterface`).
- Exceptions typées (`App\Shared\Application\Exception\NotFoundException`, `ValidationException`,
  `ConflictException`) converties en HTTP par le listener de l'epic 35 :
  pas de try/catch dans le contrôleur.
- Contrôleur admin : `RequiresAuthTrait::requireAuthenticatedAdmin()` (renvoie `User|JsonResponse`), modèle
  `api/src/Content/Presentation/Controller/PartnerController.php`.
- Nom d'un auteur : table `` `user` `` colonne `display_name` (`api/src/Identity/Domain/Entity/User.php`).
- Ajouter un contexte (`api/CLAUDE.md`, « Bounded contexts ») : (1) dossiers des 4 couches, (2)
  `DddArchitectureValidator::CONTEXTS` (`api/src/Shared/Application/Support/DddArchitectureValidator.php`), (3)
  exclusion `../src/Notes/Domain/` dans `api/config/services.yaml` (liste vers la ligne 67), (4) mapping Doctrine
  dans `api/config/packages/doctrine.yaml` (modèle `Content`), (5) la liste des contextes de `api/CLAUDE.md`, que
  `tests/Unit/Shared/StandardsDocsMatchToolingTest.php` compare à `CONTEXTS`.
- Tests : `tests/Functional/FunctionalTestCase.php` (`createUser(..., ['ROLE_USER', 'ROLE_ADMIN'])`,
  `createEvent()`, `createRegistration()`, `loginAs()`, `errorCode()`), modèle `tests/Functional/PartnerTest.php`.

## Décisions

1. **Nouveau contexte `Notes`**, qui ne connaît aucune entité métier. Tables préfixées `admin_note`.
2. **Cible = type + identifiant.** Énumération `NoteTargetType` (`Domain/Enum/`) avec la seule valeur
   `registration`. Un type inconnu est refusé (422 `note_target_type_invalid`), jamais stocké.
3. **Vérification de la cible à l'écriture** par un port `NoteTargetInterface` (`Application/Port/`) :
   `exists(NoteTargetType $type, string $id): bool`. Implémentation `Notes/Infrastructure/Adapter/`, qui délègue à
   `RegistrationRepositoryInterface::findById()` pour `registration` (un `match` sur l'énumération : ajouter un type
   oblige à traiter le cas). Cible absente → 404 `note_target_not_found`. La lecture ne vérifie pas la cible (une
   cible sans note renvoie une liste vide).
4. **Trois entités** (`Domain/Entity/`) :
   - `AdminNote` : `id`, `targetType`, `targetId`, `body`, `status` (`open` / `settled`, énumération
     `NoteStatus`), `authorId` (auteur d'origine, jamais modifié), `createdAt`, `updatedAt`.
   - `NoteFollowUp` : `id`, `noteId`, `kind` (`comment` / `settled` / `reopened`, énumération `FollowUpKind`),
     `body` (vide permis pour `settled` et `reopened`, obligatoire pour `comment`), `authorId`, `createdAt`.
   - `NoteRevision` : `id`, `noteId`, `previousBody`, `editorId`, `editedAt`.
5. **Règles du domaine (pures)** sur `AdminNote` : `write()` (statut `open`), `edit($body, $editorId, $now)`
   renvoie la `NoteRevision` du texte précédent, ou `null` si le texte (trimé) ne change pas ; `settle(...)` et
   `reopen(...)` changent le statut et renvoient le `NoteFollowUp` correspondant ; `followUp(...)` renvoie un suivi
   `comment`. Régler une note réglée → `note_already_settled`, rouvrir une note ouverte → `note_already_open`
   (409 via `ConflictException`). Une note réglée reste modifiable et peut recevoir un suivi.
6. **Textes** : note 1 à 2000 caractères, suivi 0 à 2000 (1 minimum pour un `comment`), trimés, texte brut (le
   front l'affichera tel quel, jamais en HTML).
7. **Pas de suppression** (ni note, ni suivi, ni révision) : aucune route `DELETE`.
8. **Une commande `ManageAdminNotes`** (`Application/Command/`) : `write`, `edit`, `followUp`, `settle`,
   `reopen`. Chaque méthode est une unité de travail (la note et son suivi ou sa révision sont enregistrés
   ensemble, un seul `flush`).
9. **Une requête `AdminNotesQuery`** (`Application/Query/`, interface + `Dbal*` dans `Infrastructure/Dbal/`) :
   `forTarget(type, id)`, `revisions(noteId)`, `openCounts(type, ids)`. Le nom de l'auteur est lu par jointure sur
   `` `user`.display_name `` (null si le compte n'existe plus).
10. **Migration** : `Version20261025200008` (la PR #957 de l'epic 45 ajoute `200006` et `200007` ; prendre le
    numéro suivant le dernier présent sur `develop` au moment du commit, en restant après `200007`).

## Critères d'acceptation

### Domaine

1. `AdminNote`, `NoteFollowUp`, `NoteRevision` : `final`, sans setter, sans horloge ni aléa (id et `$now` passés
   par la commande). Codes `\DomainException` : `note_body_invalid`, `note_follow_up_body_invalid`,
   `note_already_settled`, `note_already_open`.
2. `edit()` conserve `authorId`, met à jour `updatedAt`, et renvoie une révision portant l'ancien texte et
   l'éditeur ; un texte identique après trim ne crée pas de révision et ne touche pas `updatedAt`.
3. `settle()` / `reopen()` renvoient un suivi du bon `kind`, avec le texte facultatif ; l'état est refusé s'il est
   déjà atteint (AC 1).

### API (toutes les routes : admin uniquement, 401 sans session, 403 pour un membre)

4. Migration : tables `admin_note` (index sur `target_type, target_id, status`), `admin_note_follow_up` (index sur
   `note_id, created_at`), `admin_note_revision` (index sur `note_id, edited_at`), réversible.
5. `POST /api/v1/admin/notes` `{targetType, targetId, body}` → 201 `{data: {id}}`. Type inconnu → 422
   `note_target_type_invalid` ; cible absente → 404 `note_target_not_found` ; texte invalide → 422
   `note_body_invalid`.
6. `GET /api/v1/admin/notes?targetType=registration&targetId=<id>` → 200 `{data: [...], meta: {}}` :
   `{id, body, status, author: {id, displayName}, createdAt, updatedAt, edited (bool), followUps: [{id, kind,
   body, author: {id, displayName}, createdAt}]}`. Notes ouvertes d'abord, puis réglées ; dans chaque groupe, de
   la plus récente à la plus ancienne ; suivis dans l'ordre chronologique.
7. `PATCH /api/v1/admin/notes/{id}` `{body}` → 204, révision enregistrée si le texte change.
8. `POST /api/v1/admin/notes/{id}/follow-ups` `{body}` → 201 `{data: {id}}`.
9. `POST /api/v1/admin/notes/{id}/settle` et `.../reopen` `{body?}` → 204 ; état déjà atteint → 409
   `note_already_settled` / `note_already_open`.
10. `GET /api/v1/admin/notes/{id}/revisions` → 200 : `[{previousBody, editor: {id, displayName}, editedAt}]`, de
    la plus récente à la plus ancienne.
11. `POST /api/v1/admin/notes/open-counts` `{targetType, targetIds: [...]}` → 200 `{data: {"<id>": n}}` : une
    seule requête SQL quelle que soit la taille de la liste ; seules les cibles avec au moins une note ouverte
    apparaissent ; 500 ids au plus (422 `note_target_ids_invalid` au-delà ou si ce n'est pas une liste de
    chaînes).
12. Id de note inconnu → 404 `note_not_found`. Aucune route membre ni publique ne renvoie une note.

### Tests et gates

13. Unitaires `tests/Unit/Notes/AdminNoteTest.php` : bornes des textes, édition (révision ou pas), régler et
    rouvrir (et leurs refus), suivi vide refusé pour un commentaire et accepté pour un changement d'état.
14. Fonctionnels `tests/Functional/AdminNoteTest.php` : parcours complet (écrire, suivre, modifier, régler,
    rouvrir, lire, historique) ; 401 / 403 sur chaque route ; type inconnu, cible absente, note inconnue ;
    `open-counts` (cibles mélangées, note réglée non comptée, liste trop longue refusée).
15. `composer gates` vert (y compris `app:architecture:ddd` et `StandardsDocsMatchToolingTest` avec le nouveau
    contexte).

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-3, 13) - Contexte `Notes` (dossiers, `CONTEXTS`, `services.yaml`, `doctrine.yaml`,
  `api/CLAUDE.md`), énumérations, entités, tests unitaires.
- [ ] **Task 2** (AC 4) - Migration.
- [ ] **Task 3** (AC 5, 7-9) - Dépôts (`Domain/Repository/` + `Infrastructure/Doctrine/`), port
  `NoteTargetInterface` et son adaptateur, `ManageAdminNotes`.
- [ ] **Task 4** (AC 6, 10, 11) - `AdminNotesQueryInterface` + `DbalAdminNotesQuery`.
- [ ] **Task 5** (AC 5-12, 14) - `AdminNoteController` (`Presentation/Controller/`) et tests fonctionnels.
- [ ] **Task 6** (AC 15) - Gates verts, Dev Agent Record.

## Dev Notes

### Hors périmètre

- Tout le front : 46.2 (panneau sur la fiche) et 46.3 (pastille sur la liste). Cette story livre l'API et rien
  d'autre.
- Notes sur le compte utilisateur, suppression, mentions, notifications, pièces jointes : hors epic.

### Garde-fous d'architecture (`api/CLAUDE.md`)

- **AC-D1 / AC-D3** : le domaine `Notes` n'importe rien du projet ; pas d'horloge ni d'aléa dedans.
- **AC-A2** : pas de `Connection` ni d'`EntityManagerInterface` dans l'Application ; dépôts (interfaces en
  `Domain/Repository/`) et requête (interface en `Application/Query/`).
- **AC-A3** : `write()` et `followUp()` renvoient l'id (`string`) ; jamais un `array`, jamais une entité.
- **AC-P4** : un seul appel Application par action de contrôleur.
- **DBAL QueryBuilder** pour la requête (pas de SQL brut, pas de DQL) ; `openCounts` en un `GROUP BY target_id`
  avec `IN (:ids)` (`ArrayParameterType::STRING`).
- PHPStan max : narrower chaque champ du JSON (`is_string`), chaque colonne lue en DBAL.
- **AC-T5** : noms de tests en camelCase. Base de test isolée : `TEST_TOKEN=_notes` (voir `CLAUDE.md` racine).

### Pour les stories suivantes

- 46.2 consomme `GET /admin/notes`, `POST`, `PATCH`, `follow-ups`, `settle`, `reopen`, `revisions`.
- 46.3 consomme `open-counts` avec les ids de la liste des inscriptions, et `GET /admin/notes` pour la dernière
  note ouverte d'une ligne (ou un champ `latestOpen` à ajouter alors à `open-counts` si un appel par ligne est
  trop coûteux : à trancher en 46.3).

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List
