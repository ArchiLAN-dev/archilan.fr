# Story 36.3: Volet adhésion et inscriptions sur la fiche utilisateur

**Status:** review
**Epic:** 36 - Espace de contrôle utilisateur (admin)
**Date:** 2026-08-06
**Dépend de :** story 36.1 (la fiche qui accueille le volet)

## Story

En tant qu'admin,
je veux voir sur la fiche d'un membre ses adhésions et **toutes** ses inscriptions aux événements,
afin de répondre à « est-il à jour ? » et « à quoi s'est-il inscrit ? » sans ouvrir deux autres écrans
et sans parcourir les événements un par un.

## Context

Deux informations existent, aucune n'est consultable par personne :

- **Adhésions** : `/admin/adhesions` liste toutes les adhésions du site, filtrables, mais la fiche d'un
  membre n'en dit rien.
- **Inscriptions** : consultables **uniquement par événement** (`/admin/evenements/{id}/inscriptions`).
  Pour savoir à quoi une personne s'est inscrite, il faut ouvrir chaque événement.

### Ce qui existe déjà et qu'il ne faut pas réécrire

Cette story est presque entièrement de la **composition** - la vérification du code a montré que les
deux lectures existent et sont déjà filtrables par utilisateur :

| Besoin | Requête existante | Remarque |
|---|---|---|
| Historique d'adhésions | `AdminMembershipListQuery::search(..., userId: $id)` | Accepte déjà un filtre `userId` et renvoie des `MembershipView` avec les champs **admin** (note interne, commande HelloAsso, source) |
| Toutes les inscriptions | `AccountRegistrationsQuery::findForUser($userId)` | Aujourd'hui branchée sur `/compte/inscriptions`, l'espace du membre lui-même |

Aucune nouvelle requête DBAL n'est nécessaire. La valeur ajoutée est l'assemblage et la mise à
disposition côté admin, pas une nouvelle lecture.

## Acceptance Criteria

### Backend

1. **Nouvel endpoint** `GET /api/v1/admin/users/{userId}/participation`, réservé à `ROLE_ADMIN`,
   renvoyant `{ memberships: [...], registrations: [...] }` en un seul appel - le volet est rendu d'un
   bloc, deux allers-retours pour un panneau seraient gratuits.
2. **`memberships`** : l'historique complet, du plus récent au plus ancien, avec pour chacune son
   `status`, ses dates de début et de fin, sa `source` (HelloAsso, Dolibarr, manuelle), la référence de
   commande HelloAsso et la note d'admin.
3. **`registrations`** : toutes ses inscriptions, avec le titre de l'événement, sa date, le statut de
   l'inscription, le nombre de slots et le statut de session éventuel.
4. **Réutilisation stricte** : `AdminMembershipListQuery` et `AccountRegistrationsQuery` sont appelées
   telles quelles. Aucune requête DBAL n'est ajoutée, aucune des deux n'est modifiée.
5. Le contrôleur ne fait **qu'un** appel Application (AC-P4) : la composition vit dans une query
   d'application dédiée, qui renvoie `null` quand le compte n'existe pas pour permettre le 404.
6. `403` pour un non-admin, `401` pour un anonyme, `404` pour un utilisateur inconnu.
7. Tests fonctionnels : adhésion présente et champs admin exposés, inscriptions listées, listes vides,
   codes d'accès.

### Frontend

8. Un panneau **« Adhésion »** sur la fiche : l'état courant mis en avant (à jour jusqu'au …, expirée
   depuis …, jamais adhéré), puis l'historique. La note d'admin et la référence HelloAsso sont
   affichées quand elles existent.
9. Un panneau **« Inscriptions »** : la liste des événements auxquels il s'est inscrit, avec le statut,
   la date et un lien vers la page d'inscriptions de l'événement côté admin.
   *Piège relevé :* le champ d'API s'appelle `eventSlug` mais contient l'**identifiant** de l'événement
   (`DbalAccountRegistrationsQuery` mappe `'eventSlug' => $eventId` ; les événements n'ont pas encore de
   slug, cf. story 34.1). La route admin attend justement l'id, donc le lien est correct tel quel - un
   commentaire l'explique dans le composant pour que personne ne le « corrige ».
10. Chaque panneau se comporte seul : message d'erreur local en cas d'échec de chargement, sans casser
    le reste de la fiche.
    *Règle corrigée à l'implémentation :* le brouillon disait « masqué si la liste est vide », repris du
    hub public sans le réinterroger - et il contredisait l'AC 8, qui promet un état « jamais adhéré ».
    Sur un outil d'admin, « rien ici » **est** une réponse, et un panneau disparu ne se distingue pas
    d'un panneau cassé. Les panneaux restent donc visibles avec un état vide explicite. Le journal de la
    story 36.5 a été aligné sur cette règle pour que la fiche ne se comporte pas de deux façons.
11. Un statut d'adhésion est lisible d'un coup d'oeil (couleur + libellé français), jamais le code brut.

### Gates

12. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-5). `AdminUserParticipationQuery` (Identity/Application) composant les deux
  requêtes existantes + `GET /api/v1/admin/users/{userId}/participation`.
- [x] **Task 2** (AC 6,7). Tests fonctionnels.
- [x] **Task 3** (AC 8,9,10,11). Panneaux « Adhésion » et « Inscriptions » sur la fiche.
- [x] **Task 4** (AC 12). Gates des deux côtés.

## Dev Notes

- **Ne rien réécrire.** Le réflexe naturel serait d'ajouter une requête DBAL « adhésions d'un
  utilisateur » ; elle existe déjà sous la forme du filtre `userId` de `AdminMembershipListQuery`. Idem
  pour les inscriptions. Cette story doit rester une composition.
- **Import inter-contextes.** Identity va appeler des queries de Membership et de Registrations. C'est
  un appel Application -> Application, motif déjà employé (`CommunityFeedQuery` importe
  `Sessions\Application\Query\ViewableRecapsQuery`).
- `AdminMembershipListQuery::search()` est paginée : passer une limite haute mais **bornée**, un membre
  n'ayant pas des centaines d'adhésions. Ne pas prétendre à l'exhaustivité sans borne.
- **Ne pas gater sur `ROLE_MEMBER`.** L'état d'adhésion affiché doit venir des adhésions réelles, pas du
  rôle persistant, qui survit à l'expiration (AC-M1 à AC-M3). C'est justement ce que ce panneau permet
  de constater.
- La note d'admin (`adminNote`) est un champ interne : elle a sa place ici, jamais sur une surface
  publique.

### Project Structure Notes

- API : `Identity/Application/Query/AdminUserParticipation.php`, `AdminUserParticipationQuery.php`
  (nouveaux), `Identity/Presentation/Controller/AdminUserParticipationController.php` (nouveau).
- Front : `features/admin/admin-user-participation.tsx` (nouveau),
  `features/admin/admin-users-api.ts` (lecture), `features/admin/admin-user-detail.tsx` (montage).

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-36-espace-de-controle-utilisateur-admin.md]
- [Source: api/src/Membership/Application/Query/AdminMembershipListQuery.php (filtre userId + champs admin)]
- [Source: api/src/Registrations/Application/Query/AccountRegistrationsQuery.php (findForUser)]
- [Source: api/CLAUDE.md « Membership access control »]

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code).

### Completion Notes List

- `GET /api/v1/admin/users/{userId}/participation` et deux panneaux sur la fiche : « Adhésion » (avec la
  note interne et la référence HelloAsso) et « Inscriptions » (tous les événements, avec un lien vers
  la page d'inscriptions de chacun). Chaque panneau disparaît seul quand il n'a rien à montrer.
- **Story presque sans code neuf, et c'est le point.** Les deux lectures existaient et étaient déjà
  filtrables par utilisateur : `AdminMembershipListQuery::search()` accepte un `userId` et renvoie les
  champs admin, `AccountRegistrationsQuery::findForUser()` était branchée sur l'espace du membre.
  Personne ne les avait assemblées côté admin. Aucune requête DBAL ajoutée.
- Le champ `eventSlug` de l'API des inscriptions contient en fait l'**id** de l'événement. Vérifié dans
  `DbalAccountRegistrationsQuery` avant d'écrire le lien, et commenté sur place : c'est exactement le
  genre de nom trompeur qu'une relecture rapide « corrigerait » en cassant le lien.
- `Membership` n'a **pas** de constantes de statut ni de source : les valeurs sont des littéraux
  (`'active'`, `'helloasso'`). Le test utilise la fabrique `Membership::create()` plutôt que d'inventer
  des constantes inexistantes.
- L'état d'adhésion affiché vient des adhésions réelles, jamais de `ROLE_MEMBER`, qui survit à
  l'expiration (AC-M1 à AC-M3) - ce panneau sert précisément à constater l'écart.
- Gates : API `phpstan` (src + tests) / `php-cs-fixer` / `app:architecture:ddd` / `rector` verts,
  `phpunit` vert en run isolé ; frontend `typecheck` / `lint` / `test` / `build` verts.

### File List

- `api/src/Identity/Application/Query/AdminUserParticipation.php`, `AdminUserParticipationQuery.php` (new)
- `api/src/Identity/Presentation/Controller/AdminUserParticipationController.php` (new)
- `api/tests/Functional/AdminUserParticipationTest.php` (new, 7 cas)
- `frontend/src/features/admin/admin-user-participation.tsx` (new)
- `frontend/src/features/admin/admin-users-api.ts` (lecture + type guards)
- `frontend/src/features/admin/admin-user-detail.tsx` (montage du panneau)

### Change Log

| Date | Change |
|------|--------|
| 2026-08-06 | Créée (draft). Compose deux lectures existantes pour donner enfin une vue « adhésion + toutes les inscriptions » par personne. |
| 2026-08-06 | Implémentée. Endpoint de composition (aucune requête DBAL ajoutée) + panneaux Adhésion et Inscriptions. Piège `eventSlug` (qui porte un id) vérifié et commenté. Gates verts. Status -> review. |
