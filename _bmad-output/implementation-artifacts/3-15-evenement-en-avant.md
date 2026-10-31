# Story 3.15: L'admin choisit l'événement mis en avant sur l'accueil

**Status:** review
**Epic:** 3 - Cycle de vie des événements (backoffice)
**Date:** 2026-10-31
**Origine :** demande de Jean le 2026-10-31 (« J'aimerais choisir quel event mettre en avant »), cadrée le même jour.

## Story

En tant qu'admin,
je veux choisir l'événement mis en avant sur la page d'accueil,
afin de ne pas dépendre de l'ordre des dates ni du statut des événements.

## Contexte

- Aujourd'hui la section « Les LAN » de l'accueil (`HomeLan`, `frontend/src/features/home/home-sections.tsx`) prend
  le premier événement non terminé (publié ou en cours, trié par date de début), sinon le dernier terminé. Aucun
  réglage ne permet de changer ce choix.
- L'API publique (`GET /api/v1/events`, `PublicEventCatalog`) ne renvoie que les statuts publics (publié, en cours,
  terminé).

## Décisions (cadrées avec Jean)

1. **Sans choix, la règle actuelle s'applique.** Le choix de l'admin ne fait que la remplacer quand il existe.
2. **Tout événement public peut être mis en avant** (publié, en cours ou terminé : une édition passée peut rester en
   avant pour montrer son recap). Un brouillon ne peut pas être choisi ; dépublier un événement choisi le retire de
   l'accueil (le republier ne le remet pas en avant), l'accueil repasse donc sur la règle automatique.
3. **Le bandeau « Prochaine LAN : date » reste automatique** (la vraie prochaine LAN), même si un événement passé est
   mis en avant.
4. **Un seul événement en avant à la fois** : en choisir un retire le précédent, dans la même écriture.
5. **Stockage** : un booléen `featured_on_home` sur `event` (pas de réglage global séparé : un seul endroit à lire,
   et la règle « un seul à la fois » vit dans la commande).
6. **Le texte de la section suit le choix quand il est à venir** : l'image, le titre et le bouton « Voir
   l'événement » pointent sur l'événement choisi. Si l'événement choisi est terminé, l'image le montre et le texte
   garde la logique actuelle (prochaine LAN annoncée ou non).
7. **Admin** : dans la liste des événements (`/admin/evenements`), un bouton « Mettre en avant » (ou « Retirer de
   l'accueil ») sur chaque événement non brouillon, et une pastille « Accueil » sur celui qui est en avant.

## Critères d'acceptation

1. `PUT /api/v1/admin/events/{id}/featured` `{featured: bool}` (admin seulement) met en avant l'événement, ou le
   retire ; mettre en avant retire l'ancien. Brouillon refusé (422), événement inconnu 404, corps invalide 400.
2. Les pages publiques sont revalidées après l'écriture (accueil, liste et pages des événements concernés).
3. `GET /api/v1/events` et `GET /api/v1/admin/events` exposent `featuredOnHome`.
4. L'accueil montre l'événement choisi quand il y en a un dans la liste publique, sinon la règle actuelle ; le
   bandeau « Prochaine LAN » ne change pas.
5. La liste admin montre la pastille et le bouton, et se met à jour sans recharger la page.
6. Tests : domaine (mettre en avant, retirer, brouillon refusé), fonctionnels (cycle complet, un seul à la fois, 401
   et 403, 404, 422, 400), front (choix de l'accueil, liste admin).
7. `composer gates` et `pnpm gates` verts ; vérification réelle.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - API : colonne et migration, méthodes du domaine, `findFeaturedOnHome`, commande
  `FeatureEventOnHome`, route, champ dans les deux lectures.
- [x] **Task 2** (AC 4-5) - Front : `featuredOnHome` lu, choix dans `HomeLan`, bouton et pastille dans la liste admin.
- [x] **Task 3** (AC 6-7) - Tests, gates, vérification réelle.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- API : colonne `event.featured_on_home` (migration `Version20261031100000`), `Event::featureOnHome()` (refuse un
  brouillon), `removeFromHome()`, retrait automatique au passage en brouillon, `findFeaturedOnHome()`, commande
  `FeatureEventOnHome` (retire l'ancien choix dans le même flush, revalide les pages des événements touchés), route
  `PUT /api/v1/admin/events/{id}/featured`, champ `featuredOnHome` dans `PublicEventCatalog` et `AdminEventView`.
- Front : `featuredOnHome` lu (optionnel côté payload public), `homeLanPicks()` choisit l'événement affiché et celui
  dont parle le texte, `featureAdminEventOnHome()`, bouton « Mettre en avant » / « Retirer de l'accueil » et pastille
  « Accueil » dans la liste admin. Le tableau desktop réutilise maintenant `renderActions` (il dupliquait les mêmes
  boutons que la vue mobile).
- Vérification réelle (Chrome, `localhost:3000`) : ArchiLAN #2 (terminé) mis en avant depuis la liste admin, pastille
  et message affichés ; l'accueil montre #2 en image et parle toujours d'ArchiLAN 4 (prochaine LAN). Choix retiré
  ensuite. La couverture de #2 renvoie 404 sur le MinIO local (fichier absent en dev, sans lien avec la story).
- `composer gates` vert (3361 tests), `pnpm gates` vert (1331 tests).

### File List

- `api/migrations/Version20261031100000.php` (nouveau)
- `api/src/Events/Domain/Entity/Event.php` (modifié)
- `api/src/Events/Domain/Repository/EventRepositoryInterface.php` (modifié)
- `api/src/Events/Infrastructure/Doctrine/DoctrineEventRepository.php` (modifié)
- `api/src/Events/Application/Command/FeatureEventOnHome.php` (nouveau)
- `api/src/Events/Application/Query/AdminEventView.php` (modifié)
- `api/src/Events/Application/Query/PublicEventCatalog.php` (modifié)
- `api/src/Events/Application/Service/AdminEventDrafts.php` (modifié)
- `api/src/Events/Presentation/Controller/AdminEventController.php` (modifié)
- `api/tests/Unit/Events/EventFeaturedOnHomeTest.php` (nouveau)
- `api/tests/Functional/EventFeaturedOnHomeTest.php` (nouveau)
- `frontend/src/features/events/event-types.ts` (modifié)
- `frontend/src/features/events/public-events-api.ts` (modifié)
- `frontend/src/features/home/home-sections.tsx` (modifié)
- `frontend/src/features/home/home.test.tsx` (modifié)
- `frontend/src/features/admin/admin-events-api.ts` (modifié)
- `frontend/src/features/admin/admin-event-dashboard.tsx` (modifié)
- `frontend/src/features/admin/admin-event-featured.test.tsx` (nouveau)
