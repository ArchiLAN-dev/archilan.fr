# Story 36.6: Actions ciblées sur les objets d'un membre

**Status:** review
**Epic:** 36 - Espace de contrôle utilisateur (admin)
**Date:** 2026-08-07
**Dépend de :** stories 36.1 (la fiche) et 36.4 (le volet jeu, qui expose les runs)

## Story

En tant qu'admin,
je veux pouvoir agir sur les objets d'un membre depuis sa fiche quand il n'est pas disponible,
afin de débloquer une situation sans attendre - et que chaque geste reste attribué à moi.

## Context

C'est la **seule story de l'épic qui écrit sur les objets d'autrui**, et la dernière volontairement,
pour arriver sur une fiche déjà complète qui donne le contexte de chaque action.

Rappel de l'arbitrage de Jean (2026-08-06) : **pas d'usurpation**. L'admin agit depuis son propre
compte, chaque action lui étant attribuée. Aucun jeton d'usurpation n'est émis.

### Périmètre fermé

« Agir sur ses objets » déborde sans fin si on ne le ferme pas. Trois actions, choisies sur des besoins
opérationnels réels :

| Action | Besoin | Brique existante |
|---|---|---|
| Arrêter la partie en cours d'une run | Issue #387 : le propriétaire n'est pas là, la run tourne dans le vide | `ForceEndSessionCommand` - déjà le chemin admin, déjà tracé dans `run_audit_log` |
| Révoquer toutes ses sessions actives | Compte compromis, il faut couper l'accès immédiatement | `RefreshTokenRepositoryInterface::revokeAllForUser()` |
| Forcer la vérification de son email | Email de confirmation perdu, le compte reste bloqué | `User::confirmEmail()` |

**Écartées de cette story**, faute de besoin avéré ou parce qu'elles ouvrent une autre boîte :
réinitialiser le mot de passe (envoie un courriel, mérite sa propre décision produit), agir sur une
inscription (contexte Registrations, et les écrans d'inscriptions par événement existent déjà).

### Le manque : aucune traçabilité pour deux des trois

L'arrêt de partie s'auto-trace (`RunAuditLog`). Les deux autres n'ont **aucun journal** : révoquer les
sessions de quelqu'un ou valider son email à sa place sans laisser de trace contredirait l'épic entier,
dont la moitié consiste à rendre lisible ce qui a été fait.

## Acceptance Criteria

### Backend

1. **Nouveau journal `AdminUserActionAudit`** (Identity) : compte cible, admin auteur, action,
   horodatage. Avec sa migration.
2. **Trois endpoints admin**, tous réservés à `ROLE_ADMIN` :
   - `POST /api/v1/admin/users/{userId}/revoke-sessions`
   - `POST /api/v1/admin/users/{userId}/verify-email`
   - `POST /api/v1/admin/users/{userId}/runs/{runId}/stop`
3. **Chaque action est tracée** : les deux premières dans `AdminUserActionAudit`, la troisième dans
   `run_audit_log` via la commande existante, qu'il ne faut pas doubler.
4. **Un admin ne s'applique pas ces actions à lui-même** - même raison qu'ailleurs : la seule voie de
   retour serait un autre admin, et il peut ne pas y en avoir.
5. **Arrêt de run** : refuse si la run n'appartient pas au membre visé (on agit depuis *sa* fiche, pas
   sur n'importe quelle run), et si elle n'a pas de partie en cours.
6. **Réutilisation** : `ForceEndSessionCommand` est appelée telle quelle. Ni copie de sa logique, ni
   second journal.
7. `403` non-admin, `401` anonyme, `404` compte ou run inconnus.
8. Tests fonctionnels : les trois actions, leur trace, le refus sur soi-même, le refus d'une run
   n'appartenant pas au membre, les codes d'accès.

### Frontend

9. Les actions apparaissent **là où leur objet est déjà affiché** : « Arrêter la partie » sur la run
   concernée dans le volet Jeu ; « Révoquer les sessions » et « Valider l'email » dans un bloc
   « Actions » du volet Identité.
10. Chaque action demande une **confirmation explicite** et affiche son issue ; la fiche est rafraîchie
    après coup.
11. Une action impossible est **désactivée avec sa raison** (soi-même, email déjà vérifié, run sans
    partie en cours), pas seulement rejetée par le serveur.

### Journal

12. Le journal d'activité de la story 36.5 gagne `AdminUserActionAudit` comme **huitième source** :
    créer une trace que la fiche n'affiche pas reproduirait exactement le défaut que 36.5 a corrigé.

### Gates

13. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1). Entité `AdminUserActionAudit` + dépôt + migration.
- [x] **Task 2** (AC 2-7). Service applicatif `AdminUserActions` + les trois endpoints.
- [x] **Task 3** (AC 8). Tests fonctionnels.
- [x] **Task 4** (AC 12). Nouvelle source dans la frise d'activité.
- [x] **Task 5** (AC 9-11). Boutons et confirmations sur la fiche.
- [x] **Task 6** (AC 13). Gates des deux côtés.

## Dev Notes

- **Ne pas contourner `ForceEndSessionCommand`.** Elle arrête la session, appelle le runner, dépose le
  job d'archivage et écrit le journal. La réimplémenter perdrait trois de ces quatre effets.
- `PersonalRunLifecycle::stop()` **exige la propriété** (`isOwnedBy`) : ce n'est pas le bon point
  d'entrée pour un admin. Passer par la session, comme le fait déjà l'écran d'administration des
  sessions.
- La run porte son `sessionId` ; le volet Jeu (36.4) doit l'exposer pour que le bouton sache quoi
  arrêter. C'est le seul ajout à faire à la story précédente.
- **Ne pas ajouter d'usurpation.** L'arbitrage est explicite et documenté dans l'épic ; une story
  d'actions ciblées n'est pas l'endroit pour le rouvrir.
- Forcer la vérification d'email est **idempotent** côté domaine (`confirmEmail()` ne fait rien si déjà
  vérifié) : ne pas écrire de trace pour une action sans effet.

### Project Structure Notes

- API : `Identity/Domain/Entity/AdminUserActionAudit.php`,
  `Identity/Domain/Repository/AdminUserActionAuditRepositoryInterface.php`,
  `Identity/Infrastructure/Doctrine/DoctrineAdminUserActionAuditRepository.php`,
  `Identity/Application/Service/AdminUserActions.php`,
  `Identity/Presentation/Controller/AdminUserActionsController.php`, une migration,
  `Identity/Infrastructure/Dbal/DbalAdminUserActivityQuery.php` (8e source).
- Front : `features/admin/admin-user-actions.tsx`, `admin-user-gaming.tsx` (bouton d'arrêt),
  `admin-users-api.ts`, `admin-user-detail.tsx`.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-36-espace-de-controle-utilisateur-admin.md]
- [Source: issue #387]
- [Source: api/src/Sessions/Application/Command/ForceEndSessionCommand.php]

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code).

### Completion Notes List

- Trois actions livrées, en liste fermée : arrêter la partie en cours d'une run du membre, révoquer
  toutes ses sessions actives, forcer la vérification de son email. Boutons posés là où leur objet est
  déjà affiché - l'arrêt sur la run concernée dans le volet Jeu, les deux autres dans un bloc
  « Actions » en tête de fiche.
- **Un nouveau journal, parce qu'il en manquait un.** L'arrêt de partie s'auto-trace via
  `ForceEndSessionCommand` (`run_audit_log`), mais révoquer les sessions de quelqu'un ou valider son
  email à sa place n'avait **aucune** trace. `AdminUserActionAudit` (+ migration) comble ce trou.
- **Et il est lu.** Le journal est branché comme sixième source de la frise d'activité de la story 36.5,
  avec ses deux faces (subi / effectué). Écrire une trace que la fiche n'affiche pas aurait reproduit
  exactement le défaut que 36.5 a corrigé - un test fonctionnel épingle ce bout à bout.
- **`ForceEndSessionCommand` est appelée telle quelle.** Elle arrête la session, prévient le runner,
  dépose le job d'archivage et écrit son journal ; la réimplémenter aurait perdu trois de ces quatre
  effets. `PersonalRunLifecycle::stop()` n'était pas utilisable : il exige la propriété de la run.
- **L'arrêt vérifie que la run appartient bien au membre dont on regarde la fiche.** Sans ça, un admin
  agissant depuis une fiche pourrait atteindre n'importe quelle run par son identifiant.
- Forcer la vérification d'un email déjà vérifié renvoie 204 **sans écrire de trace** : le domaine est
  idempotent, et une entrée de journal pour une action sans effet polluerait la frise.
- La 36.4 expose désormais le `sessionId` d'une run - le seul ajout nécessaire à la story précédente,
  sans quoi le bouton n'aurait rien su arrêter.
- **Écartées et pourquoi** : réinitialiser le mot de passe (envoie un courriel, mérite sa propre décision
  produit) et agir sur une inscription (contexte Registrations, écrans par événement déjà existants).
- Gates : API `phpstan` (src + tests) / `php-cs-fixer` / `app:architecture:ddd` / `rector` verts,
  `phpunit` vert en run isolé ; frontend `typecheck` / `lint` / `test` / `build` verts.

### File List

- `api/src/Identity/Domain/Entity/AdminUserActionAudit.php`,
  `Domain/Repository/AdminUserActionAuditRepositoryInterface.php`,
  `Infrastructure/Doctrine/DoctrineAdminUserActionAuditRepository.php` (new)
- `api/migrations/Version20260807120000.php` (new)
- `api/src/Identity/Application/Service/AdminUserActions.php` (new)
- `api/src/Identity/Presentation/Controller/AdminUserActionsController.php` (new)
- `api/src/Identity/Application/Query/AdminUserActivityEntry.php`,
  `Infrastructure/Dbal/DbalAdminUserActivityQuery.php` (6e source de la frise)
- `api/src/Identity/Application/Query/AdminUserGaming.php`, `AdminUserGamingQuery.php` (`sessionId`)
- `api/config/services.yaml` (câblage)
- `api/tests/Functional/AdminUserActionsTest.php` (new, 10 cas)
- `frontend/src/features/admin/admin-user-actions.tsx` (new)
- `frontend/src/features/admin/admin-user-gaming.tsx` (bouton d'arrêt)
- `frontend/src/features/admin/admin-users-api.ts`, `admin-user-detail.tsx`

### Change Log

| Date | Change |
|------|--------|
| 2026-08-07 | Créée (draft). Trois actions en liste fermée, chacune tracée ; nouveau journal pour les deux qui n'en avaient pas, branché sur la frise de la 36.5. |
| 2026-08-07 | Implémentée. `AdminUserActionAudit` + migration, trois endpoints, frise étendue, boutons sur la fiche. Arrêt de run délégué à `ForceEndSessionCommand` et restreint aux runs du membre. Gates verts. Status -> review. |
