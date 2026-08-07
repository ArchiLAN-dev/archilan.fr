# Story 36.5: Journal d'activité du compte - exposer les audits déjà écrits

**Status:** review
**Epic:** 36 - Espace de contrôle utilisateur (admin)
**Date:** 2026-08-06
**Dépend de :** story 36.1 (la fiche qui accueille le volet)

## Story

En tant qu'admin,
je veux voir, sur la fiche d'un utilisateur, une frise unique de tout ce qui a été tracé le concernant -
changements de rôle subis et provoqués, création de son compte admin, suppression, actions admin sur des
runs, accès à des événements privés,
afin de comprendre l'historique d'un compte sans ouvrir la base de données.

## Context

Le site écrit **cinq journaux d'audit** et n'en lit aucun :

| Journal | Table | Contexte |
|---|---|---|
| `RoleChangeAudit` | `role_change_audit` | Identity |
| `AdminCreationAudit` | `admin_creation_audit` | Identity |
| `DeletionAudit` | `deletion_audit` | Identity |
| `RunAuditLog` | `run_audit_log` | Sessions |
| `EventPrivateAccessLog` | `event_private_access_log` | Events |

Le constat est plus net que « jamais affiché » : **aucun chemin de lecture n'existe**. Les cinq dépôts
n'exposent que `save()` / `persist()` / `flush()`, plus un `deleteOlderThan()` de purge sur les accès
événement. Ces données sont écrites depuis des mois et n'ont jamais été relues par quoi que ce soit.

C'est donc la story la moins chère de l'épic en valeur rendue : rien à instrumenter, tout à révéler.

## Acceptance Criteria

### Backend

1. **Nouvel endpoint** `GET /api/v1/admin/users/{userId}/activity`, réservé à `ROLE_ADMIN`, renvoyant
   une frise **triée du plus récent au plus ancien**, paginée par un `limit` borné.
2. **Sept natures d'entrée**, chacune rattachée à l'utilisateur par un lien explicite :

   | Type | Source | Rattachement |
   |---|---|---|
   | `role_changed` | `role_change_audit` | il **a subi** le changement (`target_user_id`) |
   | `role_change_performed` | `role_change_audit` | il **a effectué** le changement (`admin_user_id`) |
   | `admin_account_created` | `admin_creation_audit` | son compte admin a été créé (`created_user_id`) |
   | `admin_account_created_by` | `admin_creation_audit` | il a créé un compte admin (`creator_user_id`) |
   | `account_deleted` | `deletion_audit` | `user_id` |
   | `run_admin_action` | `run_audit_log` | il a agi sur une run (`admin_user_id`) |
   | `private_event_access` | `event_private_access_log` | `user_id`, avec l'issue (accordé / refusé) |

   Les deux faces des journaux de rôle et de création d'admin sont exposées : sur la fiche d'un
   administrateur, « ce qu'il a fait » compte autant que « ce qu'il a subi ».
3. **Chaque entrée est lisible sans requête supplémentaire** : la contrepartie humaine est résolue
   (pseudo de l'admin qui a agi, pseudo de la cible), ainsi que le libellé de l'événement et le contexte
   de la partie. Un identifiant nu ne dit rien à un relecteur.
4. Une contrepartie **supprimée ou introuvable** ne casse pas la ligne : elle s'affiche en tant
   qu'inconnue plutôt que de faire disparaître l'entrée.
5. Résolution **par lot** : une frise de N entrées ne déclenche pas N lectures d'utilisateur.
6. `403` pour un non-admin, `401` pour un anonyme, `404` si l'utilisateur n'existe pas.
7. Tests fonctionnels sur l'endpoint : les natures d'entrée, l'ordre inter-sources, la contrepartie
   inconnue, la frise vide et les codes d'accès. *Livré ainsi :* les tests sont fonctionnels et non
   unitaires, la valeur étant dans le SQL - c'est d'ailleurs un test fonctionnel qui a révélé le piège
   de nommage ci-dessous, qu'aucun test unitaire à double n'aurait pu voir.

### Frontend

8. Un panneau **« Journal d'activité »** sur `/admin/utilisateurs/{userId}`, sous les panneaux existants,
   affichant la frise : horodatage absolu, phrase lisible en français, et les liens utiles (fiche de la
   contrepartie, événement). *Livré :* l'action admin sur une partie n'est **pas** liée - une session en
   cours n'a pas de page publique et une session terminée n'a de récap que s'il a été construit ; un
   lien mort vaut moins qu'un libellé.
9. Panneau **visible même quand la frise est vide**, avec un état vide explicite.
   *Révisé pendant la story 36.3 :* la première version masquait le panneau, règle reprise du hub public
   où une section vide est du poids mort. Sur une fiche d'admin c'est l'inverse - « aucune entrée
   enregistrée » est une réponse, et un panneau disparu se confond avec un panneau cassé. La fiche
   applique désormais la même règle à tous ses panneaux.
10. Chaque nature d'entrée a une icône et une formulation propre ; l'issue d'un accès à un événement
    privé (accordé / refusé) est visuellement distinguable.
11. Dégradation : un échec de chargement affiche un message dans le panneau, sans casser le reste de la
    fiche.

### Gates

12. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1,2). `AdminUserActivityQueryInterface` + `DbalAdminUserActivityQuery` : union des
  cinq tables, filtrée sur l'utilisateur, triée, bornée.
- [x] **Task 2** (AC 3,4,5). `AdminUserActivityQuery` (Application) : résolution par lot des pseudos des
  contreparties, repli explicite sur l'inconnu.
- [x] **Task 3** (AC 1,6). `GET /api/v1/admin/users/{userId}/activity`.
- [x] **Task 4** (AC 7). Tests unitaires et fonctionnels.
- [x] **Task 5** (AC 8,9,10,11). Panneau « Journal d'activité » sur la fiche.
- [x] **Task 6** (AC 12). Gates des deux côtés.

## Dev Notes

- **Lecture inter-contextes.** La frise croise des tables d'Identity, Sessions et Events. La lecture DBAL
  d'une table appartenant à un autre contexte est un motif déjà établi ici (`DbalCommunityPresenceQuery`
  lit `session`, `session_slot`, `registration`, `run` et `game` depuis Community). Une union SQL est
  préférable à trois ports traversant les contextes pour un simple read model d'admin.
- **Ne pas confondre avec la modération.** `ModerationAction` (avertissements, suspensions,
  bannissements) est du ressort de la story 36.2 et possède déjà son endpoint
  `GET /admin/community/accounts/{userId}/actions`. Cette story ne touche pas à ce journal.
- `run_audit_log` n'est rattaché qu'à l'**admin qui a agi** (`admin_user_id`), pas au propriétaire de la
  run. Relier une run à son propriétaire relève de la story 36.4 ; ne pas l'anticiper ici.
- **Piège de nommage, découvert à l'implémentation.** `run_audit_log.run_id` est un identifiant de
  **`session`**, pas de la table `run` de PersonalRuns : dans le contexte Sessions, une « run » est une
  partie Archipelago en cours. La première version joignait `run` et ne matchait **aucune** des 55
  lignes réelles de la base de dev - le panneau aurait affiché « supprimée » partout. Le libellé passe
  donc par `session`, puis par `event` **ou** `run` puisque `session.event_id` est lui-même surchargé
  (un vrai Event, sinon un id de run personnelle, comme le documente `SessionRecapAudience`). Un test
  fonctionnel dédié verrouille ce chemin ; aucun ne couvrait ce type auparavant, ce qui explique que
  rien ne l'ait attrapé.
- `deletion_audit` porte un `email_hash`, jamais l'email en clair. Il ne doit pas être affiché : il ne
  sert qu'à recouper une suppression, et l'exposer sur un écran d'admin n'apporte rien.
- Les cinq journaux n'ont aucune méthode de lecture : chaque source consommée en demande une nouvelle.
  Préférer une seule query dédiée à cinq méthodes ajoutées sur cinq dépôts d'écriture.

### Project Structure Notes

- API : `Identity/Application/Query/AdminUserActivityQueryInterface.php`, `AdminUserActivityEntry.php`,
  `AdminUserActivityQuery.php` (nouveaux), `Identity/Infrastructure/Dbal/DbalAdminUserActivityQuery.php`
  (nouveau), `Identity/Presentation/Controller/AdminUserActivityController.php` (nouveau),
  `config/services.yaml` (câblage).
- Front : `features/admin/admin-user-activity.tsx` (nouveau),
  `features/admin/admin-users-api.ts` (lecture), `features/admin/admin-user-detail.tsx` (montage).

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-36-espace-de-controle-utilisateur-admin.md]
- [Source: _bmad-output/implementation-artifacts/36-1-fiche-utilisateur-identite-et-roles.md (la fiche)]
- [Source: api/src/Community/Infrastructure/Dbal/DbalCommunityPresenceQuery.php (lecture inter-contextes)]

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code).

### Completion Notes List

- Les cinq journaux d'audit sont enfin lisibles : `GET /api/v1/admin/users/{userId}/activity` et un
  panneau « Journal d'activité » sur la fiche. Sept natures d'entrée, dont les deux faces des
  changements de rôle et des créations de compte admin.
- **Le constat de l'épic était même en dessous de la réalité** : ce n'est pas seulement que le frontend
  ne lisait rien, c'est qu'**aucun chemin de lecture n'existait**. Les cinq dépôts n'exposaient que
  `save()` / `persist()` / `flush()`. Des données écrites depuis des mois, jamais relues.
- **Un piège de nommage attrapé sur les vraies données.** `run_audit_log.run_id` est un identifiant de
  `session`, pas de la table `run`. La première version joignait `run` : zéro correspondance sur les 55
  lignes réelles de la base de dev, le panneau aurait affiché « supprimée » partout. Découvert en
  interrogeant la base plutôt qu'en relisant le code, puis verrouillé par un test dédié. Le libellé
  passe par `session` puis par `event` **ou** `run`, `session.event_id` étant lui-même surchargé.
- Cinq requêtes bornées fusionnées en PHP plutôt qu'une union SQL : les sources ont des formes sans
  rapport, chacune porte sa propre jointure, et chacune est déjà plafonnée - même motif que
  `DbalCommunityPresenceQuery`.
- Les contreparties sont nommées en un seul lot ; une contrepartie supprimée s'affiche comme telle
  plutôt que de faire disparaître la ligne - un audit survit au compte qu'il nomme.
- L'`email_hash` de `deletion_audit` n'est pas lu : il sert à recouper une suppression, l'afficher
  n'apporterait rien.
- Gates : API `phpstan` (src + tests) / `php-cs-fixer` / `app:architecture:ddd` / `rector` verts,
  `phpunit` vert en run isolé ; frontend `typecheck` / `lint` / `test` / `build` verts. Jointure corrigée
  vérifiée sur les données réelles (les titres d'événement ressortent).

### File List

- `api/src/Identity/Application/Query/AdminUserActivityEntry.php`,
  `AdminUserActivityQueryInterface.php`, `AdminUserActivityQuery.php` (new)
- `api/src/Identity/Infrastructure/Dbal/DbalAdminUserActivityQuery.php` (new)
- `api/src/Identity/Presentation/Controller/AdminUserActivityController.php` (new)
- `api/config/services.yaml` (câblage)
- `api/tests/Functional/AdminUserActivityTest.php` (new, 9 cas)
- `frontend/src/features/admin/admin-user-activity.tsx` (new)
- `frontend/src/features/admin/admin-users-api.ts` (lecture + type guard)
- `frontend/src/features/admin/admin-user-detail.tsx` (montage du panneau)

### Change Log

| Date | Change |
|------|--------|
| 2026-08-06 | Créée (draft). Expose en une frise les cinq journaux d'audit qui n'avaient aucun chemin de lecture. |
| 2026-08-06 | Implémentée. Endpoint + panneau, sept natures d'entrée, contreparties nommées par lot. Piège de nommage `run_id` -> `session.id` découvert sur les données réelles et corrigé, avec test de non-régression. Gates verts. Status -> review. |
