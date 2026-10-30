# Story 45.3 - Les partenaires d'un événement

**Status:** review
**Epic:** 45 - Mettre en avant nos partenaires (`_bmad-output/planning-artifacts/epics/epic-45-partenaires.md`)
**Date:** 2026-10-30
**Branche:** `feature/epic-45-story-3-partenaires-evenement` (partie de `feature/epic-45-story-2-partenaires-site` ;
PR à recibler sur `develop` quand 45.1 et 45.2 sont mergées)
**Dépend de:** 45.1 (partenaires, `GET /api/v1/partners`), 45.2 (`getPartners()`, `PartnerCard`)

## Story

En tant qu'admin,
je veux associer des partenaires à un événement, avec une mention libre (« Lots offerts par... »),
afin de remercier ceux qui soutiennent une LAN précise, sur sa page et sur le récap de ses parties.

## Contexte (cartographie du 2026-10-30)

- **Event** (`api/src/Events/Domain/Entity/Event.php`) : colonnes JSON déjà utilisées (`photo_gallery`,
  `game_selection_config`). Modèle de commande admin : `AdminEventRecap` (`findById`, `NotFoundException`,
  `ValidationException`, `save`, `PublicPagesRevalidation::eventChanged`), route
  `PATCH /api/v1/admin/events/{eventId}/recap` dans `AdminEventController`. Modèle GET + écriture de sous-ressource :
  `game-selection`.
- **Lecture publique** : `PublicEventCatalog::payload()` (`GET /api/v1/events`, `/events/{id}`), lue côté front par
  `getPublicEvent()` (`src/features/events/public-events-api.ts`, garde `isPublicEventPayloadItem`).
- **Page événement** : `src/app/(public)/evenements/[eventSlug]/page.tsx`.
- **Récap de partie** : `SessionRecapQuery` (`GET /api/v1/sessions/{id}/recap`), `Session::getEventId()` désigne un
  événement, une run perso ou une run hebdo (résolution par `EventRepositoryInterface::findById`, comme
  `RunResultsQuery::resolveEventName`). Front : `src/app/(public)/parties/[sessionId]/page.tsx`,
  `src/features/recap/recap-api.ts`.
- **Admin événement** : actions par ligne dans `src/features/admin/admin-event-dashboard.tsx` (`renderActions`),
  sous-page modèle `src/app/(admin)/admin/evenements/[eventId]/pelles/page.tsx`.

## Décisions

1. **Référence par id, sans import de Content** : l'événement stocke `[{partnerId, mention}]` (colonne JSON
   `partners`, nullable). Le front joint ces références à la liste publique `getPartners()` : un partenaire masqué,
   sans logo ou supprimé disparaît donc de tous les événements, passés compris, sans écriture côté Events.
2. **Au plus 5 partenaires par événement** (le plafond de l'epic), ids uniques au format des ids de partenaire
   (32 hex), mention facultative de 120 caractères au plus, l'ordre choisi par l'admin est conservé.
3. **Sous-page admin** `/admin/evenements/{id}/partenaires` : liste des partenaires (admin, avec leur état), case à
   cocher + mention + ordre ; un partenaire masqué peut être choisi (il apparaîtra quand il sera affiché), son état
   est indiqué.
4. **Page événement** : bloc « Avec le soutien de » sous la description (logos, nom, mention, lien sponsorisé),
   absent sans partenaire visible.
5. **Récap** : `eventId` ajouté au récap (null hors événement) ; bloc discret en fin de récap pour les seules parties
   d'événement. Un événement privé (lecture publique `null`) n'affiche pas ses partenaires sur le récap.
6. Toute écriture revalide `event:<id>` et `events` (`eventChanged`).

## Critères d'acceptation

1. Domaine : `Event::choosePartners(list<{partnerId, mention}>, now)` (normalise, mention vide = null),
   `getPartners()` ; erreurs `\DomainException` au-delà de 5, id invalide, doublon, mention trop longue.
2. Migration : colonne `event.partners JSON NULL`, `down()` la supprime.
3. Application : `ChooseEventPartners::choose(eventId, input)` (`NotFoundException`, `ValidationException` sur
   `partners`), revalidation après l'écriture ; lecture admin `AdminEventPartnersQuery::forEvent(eventId)`.
4. API : `GET` et `PUT /api/v1/admin/events/{eventId}/partners` (admin, `{partners: [{partnerId, mention}]}`) ;
   `partners` exposé dans la lecture publique de l'événement.
5. Récap : `eventId` (string|null) dans `GET /api/v1/sessions/{id}/recap`.
6. Front admin : sous-page, lien « Partenaires » dans les actions d'un événement, enregistrement avec retour d'erreur.
7. Front public : bloc sur la page événement et en fin de récap, jointure avec `getPartners()` dans l'ordre de
   l'événement, rien sans partenaire visible.
8. Tests : unitaires domaine, fonctionnels API (droits, validation, 404, lecture publique, revalidation, récap),
   front (jointure, garde, rendu du bloc).
9. `composer gates` et `pnpm gates` verts ; vérification réelle dans le navigateur.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Domaine + migration.
- [x] **Task 2** (AC 3, 4) - Commande, lecture admin, routes, lecture publique.
- [x] **Task 3** (AC 5) - `eventId` dans le récap.
- [x] **Task 4** (AC 6) - Sous-page admin.
- [x] **Task 5** (AC 7) - Blocs page événement et récap.
- [x] **Task 6** (AC 8, 9) - Tests, gates, vérification réelle.

## Dev Notes

- Hors périmètre : écran OBS / LAN (45.4).
- Gates front en local : `NEXT_PUBLIC_APP_URL=http://localhost:3000` (voir 45.1).

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- API : colonne `event.partners` (JSON, migration `Version20261025200005`), `Event::choosePartners()` /
  `getPartners()` (5 au plus, ids 32 hex uniques, mention de 120 caractères au plus, mention vide = null) ;
  `ChooseEventPartners` (422 sur `partners`, revalidation `events` + `event:<id>`), `AdminEventPartnersQuery`,
  `GET`/`PUT /api/v1/admin/events/{eventId}/partners` ; `partners` dans la lecture publique ; `eventId` dans le
  récap (null hors événement, résolu par le dépôt des événements).
- Front : `EventPartnerRef`, `joinEventPartners()`, `fetchAdminEventPartners()`, `saveEventPartners()` dans
  `partners-api.ts` ; `EventPartners` (variantes `page` et `discreet`) dans `partner-card.tsx` ; bloc sur la page
  événement après les détails pratiques ; bloc discret en fin de récap (`SessionRecapView`, prop `eventPartners`) ;
  sous-page admin `/admin/evenements/{id}/partenaires` et lien « Partenaires » dans les deux listes d'actions.
- Gates : `composer gates` vert ; `pnpm gates` vert (1305 tests, build) avec `NEXT_PUBLIC_APP_URL=http://localhost:3000`.
- Vérification réelle (Chrome, 2 partenaires de test avec logo, ArchiLAN #3) : ajout des deux, mention, ordre
  inversé, enregistrement confirmé ; page événement : bloc dans l'ordre choisi, mention à la place de la description ;
  récap : bloc discret en dernier (session finie rattachée le temps du test à l'événement, aucune partie
  d'événement locale n'ayant de récap, puis remise en état). Données de test supprimées.
- Limite locale : sur le serveur de dev, la page événement avec partenaires plantait à l'hydratation (« Element type
  is invalid », module client périmé de Turbopack après le build lancé en parallèle) ; le build de production
  (`next start`) l'affiche sans erreur. Redémarrer `next dev` règle le dev local.

### File List

- `api/migrations/Version20261025200005.php` (nouveau)
- `api/src/Events/Domain/Entity/Event.php` (modifié)
- `api/src/Events/Application/Command/ChooseEventPartners.php` (nouveau)
- `api/src/Events/Application/Query/AdminEventPartnersQuery.php` (nouveau)
- `api/src/Events/Application/Query/PublicEventCatalog.php` (modifié)
- `api/src/Events/Presentation/Controller/AdminEventController.php` (modifié)
- `api/src/Sessions/Application/Query/SessionRecapQuery.php` (modifié)
- `api/tests/Unit/Events/EventPartnersTest.php` (nouveau)
- `api/tests/Functional/EventPartnersTest.php` (nouveau)
- `api/tests/Functional/PublicPagesRevalidationTest.php` (modifié)
- `api/tests/Functional/SessionRecapEndpointTest.php` (modifié)
- `frontend/src/features/content/partners-api.ts` (modifié)
- `frontend/src/features/content/partner-card.tsx` (modifié)
- `frontend/src/features/content/event-partners.test.tsx` (nouveau)
- `frontend/src/features/events/event-types.ts` (modifié)
- `frontend/src/features/events/public-events-api.ts` (modifié)
- `frontend/src/features/recap/recap-api.ts` (modifié)
- `frontend/src/features/recap/session-recap-page.tsx` (modifié)
- `frontend/src/features/admin/admin-event-partners.tsx` (nouveau)
- `frontend/src/features/admin/admin-event-dashboard.tsx` (modifié)
- `frontend/src/app/(admin)/admin/evenements/[eventId]/partenaires/page.tsx` (nouveau)
- `frontend/src/app/(public)/evenements/[eventSlug]/page.tsx` (modifié)
- `frontend/src/app/(public)/parties/[sessionId]/page.tsx` (modifié)
