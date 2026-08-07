# Story 36.2: Volet modération et signalements sur la fiche utilisateur

**Status:** review
**Epic:** 36 - Espace de contrôle utilisateur (admin)
**Date:** 2026-08-06
**Dépend de :** story 36.1 (la fiche qui accueille le volet)

## Story

En tant qu'admin,
je veux voir sur la fiche d'un membre son état de modération, l'historique des sanctions et la pression
de signalements qui pèse sur lui, et pouvoir sanctionner ou lever depuis là,
afin de traiter un cas sans avoir à recoller la fiche et l'écran de modération.

## Context

La modération existe **en entier** côté API - c'est la story 30.29 - mais elle n'est visible que depuis
`/admin/moderation`, jamais depuis la fiche d'une personne :

| Brique | Où elle est |
|---|---|
| Avertir / suspendre / bannir / lever | `POST /admin/community/accounts/{userId}/{warn,suspend,ban,lift}` |
| Historique des sanctions | `GET /admin/community/accounts/{userId}/actions` |
| Signalements non résolus d'un compte | `AccountReportScoreQueryInterface::unresolvedProblemsForAccount()` |
| Pondération de gravité | `ReportSeverity::sum()` (VO de domaine Community) |
| État courant (suspendu / banni / motif) | `User::getSuspendedUntil()`, `getBannedAt()`, `getModerationReason()` |

Le seul vrai manque est la **lecture de l'état courant depuis Community** : le port
`MemberModerationGatewayInterface` est en **écriture seule** (`suspendUntil`, `ban`, `lift`). Community
sait sanctionner un membre mais pas dire s'il est déjà sanctionné.

## Acceptance Criteria

### Backend

1. **`MemberModerationGatewayInterface` gagne une lecture** de l'état courant, implémentée par
   l'adaptateur Identity existant. C'est le port prévu pour ça ; Community ne doit pas se mettre à lire
   `User` directement.
2. **Nouvel endpoint** `GET /api/v1/admin/community/accounts/{userId}/moderation`, réservé aux admins,
   renvoyant en un appel : l'état courant, l'historique des sanctions et la pression de signalements.
3. **État courant** : suspendu jusqu'à quand, banni depuis quand, motif.
4. **Historique** : les sanctions les plus récentes d'abord, chacune avec **le nom de l'admin** qui l'a
   posée - `AccountModerationService::history()` ne rend qu'un `actorId`, un identifiant nu ne dit rien.
   Résolution **par lot**.
   *Découvert au test :* `cards()` ne convient pas pour ça. Elle ne renvoie que les membres **listables**
   (slug public, ni banni ni suspendu) parce qu'elle alimente des surfaces publiques - or un admin n'a
   souvent aucun profil public, et il serait donc resté anonyme sur chacune de ses sanctions. D'où
   `namesFor()`, une lecture de noms qui n'exclut que les comptes supprimés.
5. **Pression de signalements** : le nombre de signalements de profil non résolus et leur score de
   gravité pondéré, via les briques existantes. Aucune duplication de la table de pondération.
6. **Le volet vit dans Community**, pas dans Identity : la modération lui appartient, et c'est là que
   sont déjà les actions que le panneau déclenche. Contrairement aux volets 36.3 et 36.5, l'endpoint
   n'est donc pas sous `/admin/users/{id}/...`.
7. `403` pour un non-admin, `401` pour un anonyme.
8. Tests fonctionnels : état d'un compte sain, d'un compte suspendu, d'un compte banni, historique avec
   acteur nommé, score de gravité, codes d'accès.

### Frontend

9. Un panneau **« Modération »** sur `/admin/utilisateurs/{userId}` : bandeau d'état (sain / suspendu
   jusqu'au … / banni depuis le …, avec le motif), pression de signalements, puis l'historique.
10. **Actions depuis la fiche** : avertir, suspendre (avec une échéance), bannir, lever - chacune avec un
    motif obligatoire, et la liste rafraîchie après coup.
11. Un compte **admin ne peut pas être sanctionné** : le serveur le refuse déjà (`forbidden`), l'UI doit
    le dire au lieu de proposer des boutons qui échoueront.
12. Panneau visible même sans historique, avec un état vide explicite - la règle de la fiche fixée en
    36.3.

### Gates

13. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1). Lecture d'état sur le port + adaptateur Identity.
- [x] **Task 2** (AC 2-6). `AccountModerationOverviewQuery` (Community) + endpoint.
- [x] **Task 3** (AC 7,8). Tests fonctionnels.
- [x] **Task 4** (AC 9-12). Panneau « Modération » et ses actions.
- [x] **Task 5** (AC 13). Gates des deux côtés.

## Dev Notes

- **Ne pas importer `ReportSeverity` depuis un autre contexte.** C'est un VO de domaine Community ; le
  score doit être calculé **dans** Community. C'est la raison principale pour laquelle ce volet vit ici
  et non dans Identity comme les deux précédents.
- **Ne pas dupliquer les pondérations.** `ReportSeverity::WEIGHTS` est la seule table ; une seconde copie
  côté front dériverait au premier ajustement.
- `AccountModerationService` refuse déjà de sanctionner un admin et l'acteur lui-même
  (`forbidden`) : l'UI reflète cette règle, elle ne la réimplémente pas.
- Les signalements **émis** par le membre ne sont pas exposés : aucune requête ne les sert
  (`ReportQueryFilters` n'a pas de filtre « auteur »), et en inventer une dépasse le périmètre de ce
  volet. À traiter à part si le besoin se confirme.
- Les commentaires signalés restent au niveau du contenu (masquer / restaurer) et sont hors de
  l'escalade de compte **par conception** (story 30.28) : ce panneau ne les montre pas.

### Project Structure Notes

- API : `Community/Application/Port/MemberModerationGatewayInterface.php` (+ état),
  `Community/Application/Port/MemberModerationState.php` (nouveau),
  `Identity/Infrastructure/Adapter/IdentityMemberModerationGateway.php`,
  `Community/Application/Query/AccountModerationOverviewQuery.php` (nouveau),
  `Community/Presentation/Controller/AccountModerationController.php` (action de lecture).
- Front : `features/admin/admin-user-moderation.tsx` (nouveau), `features/admin/admin-users-api.ts`,
  `features/admin/admin-user-detail.tsx` (montage).

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-36-espace-de-controle-utilisateur-admin.md]
- [Source: _bmad-output/implementation-artifacts/30-29-account-moderation-actions.md]
- [Source: api/src/Community/Application/Service/AccountModerationService.php]

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code).

### Completion Notes List

- Panneau « Modération » sur la fiche : bandeau d'état (sain / suspendu / banni + motif), pression de
  signalements (nombre non résolus + gravité pondérée), historique des sanctions avec l'admin nommé, et
  les quatre actions depuis la fiche.
- **La seule brique qui manquait était une lecture.** Tout le reste existe depuis la story 30.29. Le port
  `MemberModerationGatewayInterface` était en écriture seule : Community savait sanctionner un membre mais
  pas dire s'il l'était déjà. Ajout de `currentState()` sur le port, implémenté par l'adaptateur Identity
  existant - donc sans que Community aille lire `User` directement.
- **Un bug attrapé par le test, pas par la relecture.** La première version nommait l'acteur via
  `cards()`. Cette lecture ne renvoie que les membres listables - slug public, ni banni ni suspendu -
  parce qu'elle sert des surfaces publiques. Un admin sans profil public serait resté anonyme sur toutes
  ses sanctions, et un membre banni n'aurait pas pu être nommé dans son propre journal. Remplacée par
  `namesFor()`, qui n'exclut que les comptes supprimés.
- Le volet vit dans **Community**, contrairement aux volets 36.3 et 36.5 qui sont dans Identity : la
  modération appartient à ce contexte, et surtout la pondération de gravité est un VO de domaine
  Community (`ReportSeverity`) qu'il ne faut ni importer d'ailleurs ni recopier.
- L'UI reflète la règle serveur (ni un admin ni soi-même) au lieu de proposer des boutons qui
  échoueraient - `AccountModerationService` la porte déjà, elle n'est pas réimplémentée.
- Les signalements **émis** par le membre ne sont pas exposés : `ReportQueryFilters` n'a pas de filtre
  « auteur » et en inventer un dépassait ce volet.
- Gates : API `phpstan` (src + tests) / `php-cs-fixer` / `app:architecture:ddd` / `rector` verts,
  `phpunit` vert en run isolé ; frontend `typecheck` / `lint` / `test` / `build` verts.

### File List

- `api/src/Community/Application/Port/MemberModerationState.php` (new)
- `api/src/Community/Application/Port/MemberModerationGatewayInterface.php` (lecture d'état)
- `api/src/Identity/Infrastructure/Adapter/IdentityMemberModerationGateway.php` (implémentation)
- `api/src/Community/Application/Query/AccountModerationOverviewQuery.php` (new)
- `api/src/Community/Application/Query/CommunityUserDirectoryQueryInterface.php`,
  `api/src/Community/Infrastructure/Dbal/DbalCommunityUserDirectoryQuery.php` (`namesFor`)
- `api/src/Community/Presentation/Controller/AccountModerationController.php` (endpoint de lecture)
- `api/tests/Functional/AdminAccountModerationOverviewTest.php` (new, 7 cas)
- `frontend/src/features/admin/admin-user-moderation.tsx` (new)
- `frontend/src/features/admin/admin-users-api.ts` (lecture + actions)
- `frontend/src/features/admin/admin-user-detail.tsx` (montage du panneau)

### Change Log

| Date | Change |
|------|--------|
| 2026-08-06 | Créée (draft). Rend la modération lisible et actionnable depuis la fiche ; ajoute la seule brique manquante, la lecture d'état sur le port de modération. |
| 2026-08-06 | Implémentée. `currentState()` sur le port + `namesFor()` pour nommer un admin sans profil public (bug attrapé au test), requête de synthèse dans Community, panneau et actions sur la fiche. Gates verts. Status -> review. |
