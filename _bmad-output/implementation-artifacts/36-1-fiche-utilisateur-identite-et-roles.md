# Story 36.1: Fiche utilisateur admin - coquille, identité et gestion complète des rôles

**Status:** review
**Epic:** 36 - Espace de contrôle utilisateur (admin)
**Date:** 2026-08-06
**Referme:** issue #250 (« Refacto: Page Admin User »)

## Story

En tant qu'admin,
je veux ouvrir la fiche d'un utilisateur et y voir son identité, l'état de son compte et ses rôles,
et pouvoir le promouvoir ou le rétrograder administrateur,
afin de ne plus avoir à croiser trois écrans pour savoir qui est quelqu'un, et de ne plus dépendre
d'un accès base pour donner ou retirer les droits d'admin.

## Context

`/admin/utilisateurs` est un tableau plat (email, rôle, statut, date de création) avec un basculement
`user` <-> `member` et un formulaire de création d'admin. Il n'existe **aucune** vue de détail : pas de
route `/admin/utilisateurs/{userId}`.

L'issue #250 dit « il attribue juste le ROLE_MEMBER qui n'existe plus ». La formulation est inexacte -
`ROLE_MEMBER` existe toujours (`User.php:239`), il est simplement interdit comme garde d'accès et
réservé à l'affichage/filtrage (AC-M1 à AC-M3). Le vrai manque est ailleurs et il est net :

- `AdminChangeUserRole::normalizeRole()` ne connaît que `'user'` et `'member'` ;
- la commande **refuse toute action sur un compte déjà admin** (`in_array('ROLE_ADMIN', ...)`) ;
- `User::promoteToMember()` / `demoteToUser()` lèvent un `DomainException` sur un admin.

Conséquence : **personne ne peut promouvoir ni rétrograder un administrateur depuis l'interface.** La
seule voie existante est `POST /admin/users/admins`, qui crée un compte admin *ex nihilo* - elle ne
permet pas de promouvoir un membre déjà inscrit.

Cette story pose aussi la coquille de la fiche : les stories 36.2 à 36.6 y brancheront leurs volets.

## Acceptance Criteria

### Backend - lecture

1. **Nouvel endpoint** `GET /api/v1/admin/users/{userId}`, réservé à `ROLE_ADMIN`, renvoyant le détail
   d'identité : `id`, `email`, `displayName`, `slug`, `role` (rôle principal), `roles`, `status`,
   `emailVerified`, `createdAt`, `updatedAt`, `deletedAt`.
2. `404` avec un code d'erreur explicite si l'utilisateur n'existe pas ; `403` pour un non-admin.
3. La lecture passe par une query dédiée en `Identity/Application/Query/`, le contrôleur ne faisant
   qu'un appel Application (AC-P4).

### Backend - rôles

4. **`PATCH /api/v1/admin/users/{userId}/role` accepte désormais `admin`**, en plus de `user` et
   `member`, et sait **rétrograder** un admin vers `member` ou `user`.
5. **Le site conserve toujours au moins un administrateur.**
   *Corrigé à l'implémentation :* le brouillon demandait deux gardes distincts - l'anti-auto-modification
   et un garde « dernier admin » avec comptage. Le second est **inatteignable** et a donc été retiré
   plutôt que livré en code mort : pour le déclencher il faudrait qu'un admin rétrograde le dernier
   admin, or si c'est le dernier, c'est nécessairement l'acteur lui-même - et la règle
   anti-auto-modification l'a déjà refusé. L'invariant tient par récurrence à partir d'un seul garde.
   Un test fonctionnel épingle l'invariant (et non le garde disparu). La suppression de compte est un
   autre chemin, qui ne passe pas par cette commande.
6. `User` gagne des transitions dédiées (`promoteToAdmin` / `demoteFromAdmin`) plutôt qu'un
   assouplissement des gardes existantes de `promoteToMember` / `demoteToUser` : ces deux-là doivent
   continuer à refuser un compte admin, sans quoi un basculement `member` accidentel effacerait des
   droits d'administration.
7. Un compte supprimé reste non modifiable.
8. Le changement continue d'écrire un `RoleChangeAudit` et de déclencher la synchronisation Discord,
   pour toutes les transitions y compris celles d'admin.

### Frontend

9. **Nouvelle route `/admin/utilisateurs/{userId}`** : en-tête (pseudo, email, badge de rôle, badge
   d'état), panneau « Identité » (slug, email vérifié ou non, dates de création et de mise à jour,
   date de suppression le cas échéant) et panneau « Rôles ».
10. Le panneau « Rôles » permet de passer l'utilisateur à `user`, `member` ou `admin`, avec la
    confirmation explicite déjà en place. Les transitions impossibles (son propre compte, un compte
    supprimé) sont **désactivées avec leur raison affichée**, pas seulement rejetées par le serveur
    après coup.
11. Chaque ligne de `/admin/utilisateurs` mène à la fiche.
12. La page est réservée aux admins et suit le traitement existant du refus (écran dédié `denied`).

### Gates

13. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 6,7). `User::promoteToAdmin()` / `demoteFromAdmin()` + tests unitaires du domaine,
  dont la non-régression : `promoteToMember` / `demoteToUser` refusent toujours un admin.
- [x] **Task 2** (AC 5). ~~`UserRepositoryInterface::countAdmins()`~~ - abandonné : le garde qu'il
  devait alimenter s'est révélé inatteignable (voir AC 5). Aucun code ajouté.
- [x] **Task 3** (AC 4,5,8). `AdminChangeUserRole` : `admin` accepté, rétrogradation gérée, audit et
  sync Discord préservés.
- [x] **Task 4** (AC 1,2,3). `AdminUserDetailQuery` + `GET /api/v1/admin/users/{userId}`.
- [x] **Task 5** (AC 9,10,11,12). Page `/admin/utilisateurs/{userId}`, panneaux Identité et Rôles,
  lien depuis l'annuaire.
- [x] **Task 6** (AC 13). Gates des deux côtés.

## Dev Notes

- **Ne pas relâcher les gardes existantes.** `promoteToMember` et `demoteToUser` lèvent un
  `DomainException` sur un admin ; c'est ce qui empêche un clic « membre » de détruire des droits
  d'admin. Les nouvelles transitions sont des méthodes distinctes.
- `demoteToUser()` réécrit `roles` à `['ROLE_USER']` : `demoteFromAdmin()` doit au contraire **retirer
  seulement** `ROLE_ADMIN` et préserver un éventuel `ROLE_MEMBER`, sinon rétrograder un admin lui ferait
  perdre son statut de membre au passage.
- L'ordre des transitions compte : quand la cible est admin, `demoteFromAdmin()` doit passer **avant**
  `promoteToMember()` / `demoteToUser()`, sinon ces deux-là lèvent leur `DomainException` sur un compte
  encore admin.
- La fiche est une concentration de données personnelles sur un écran (voir les risques de l'épic) :
  `ROLE_ADMIN` strict, aucune indexation.
- Les stories suivantes ajouteront leurs volets à cette page ; prévoir une composition en sections
  autonomes plutôt qu'un composant monolithique (leçon de la story 30.36 sur `AccountTabs`).

### Project Structure Notes

- API : `Identity/Domain/Entity/User.php`, `Identity/Domain/Repository/UserRepositoryInterface.php` +
  impl Doctrine, `Identity/Application/Command/AdminChangeUserRole.php`,
  `Identity/Application/Query/AdminUserDetailQuery.php` (nouveau), contrôleur admin users.
- Front : `app/(admin)/admin/utilisateurs/[userId]/page.tsx` (nouveau),
  `features/admin/admin-user-detail.tsx` (nouveau), `features/admin/admin-users-api.ts`,
  `features/admin/admin-user-directory.tsx` (lien vers la fiche).

### References

- [Source: issue #250]
- [Source: _bmad-output/planning-artifacts/epics/epic-36-espace-de-controle-utilisateur-admin.md]
- [Source: api/src/Identity/Application/Command/AdminChangeUserRole.php (gardes actuelles + audit)]
- [Source: api/CLAUDE.md « Membership access control » (AC-M1 a AC-M3 : ROLE_MEMBER n'est pas un garde)]

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code).

### Completion Notes List

- `/admin/utilisateurs/{userId}` existe : en-tête (rôle, état, email non vérifié), panneau Identité
  (slug + lien vers le profil public, email vérifié, dates, rôles techniques) et panneau Rôles. Chaque
  ligne de l'annuaire y mène.
- Un compte existant peut enfin être **promu ou rétrogradé administrateur**. Jusqu'ici la seule voie
  était `POST /admin/users/admins`, qui crée un admin de zéro : promouvoir un membre déjà inscrit
  demandait un accès base.
- **Un garde retiré parce qu'inatteignable.** Le brouillon prévoyait un contrôle « dernier admin » avec
  comptage. Impossible à déclencher : rétrograder le dernier admin suppose que la cible soit le dernier,
  donc l'acteur lui-même, et la règle anti-auto-modification refuse déjà ce cas. Le garde et la méthode
  `countAdmins()` ont été supprimés plutôt que livrés en code mort ; un test fonctionnel épingle
  l'invariant (le site garde toujours un admin) au lieu d'une branche morte.
- `demoteFromAdmin()` ne retire que `ROLE_ADMIN` là où `demoteToUser()` réinitialise tout le jeu de
  rôles : sans ça, rétrograder un admin lui coûtait aussi son statut de membre. Couvert par un test.
- Les gardes de `promoteToMember` / `demoteToUser` sur un compte admin sont **conservées** - c'est ce qui
  empêche un clic « membre » de détruire des droits d'administration - d'où des transitions dédiées
  plutôt qu'un assouplissement.
- Première version de la fiche écrite avec `useEffect` + `setState` ; le lint l'a refusée (AC-HK2) et le
  standard AC-API4 impose TanStack Query pour toute lecture côté client. Réécrite avec `useQuery` +
  `invalidateQueries`, comme l'annuaire voisin.
- Gates : API `phpstan` (src + tests) / `php-cs-fixer` / `app:architecture:ddd` / `rector` verts,
  `phpunit` vert en run isolé ; frontend `typecheck` / `lint` / `test` / `build` verts.

### File List

- `api/src/Identity/Domain/Entity/User.php` (`promoteToAdmin`, `demoteFromAdmin`)
- `api/src/Identity/Application/Command/AdminChangeUserRole.php` (rôle `admin`, ordre des transitions)
- `api/src/Identity/Application/Query/AdminUserDetail.php`, `AdminUserDetailQuery.php` (new)
- `api/src/Identity/Presentation/Controller/AdminUserDetailController.php` (new)
- `api/tests/Unit/Identity/UserAdminRoleTransitionsTest.php` (new)
- `api/tests/Functional/AdminUserDetailTest.php` (new)
- `api/tests/Functional/AdminUserRoleTest.php` (l'ancien test figeait l'interdiction levée)
- `frontend/src/app/(admin)/admin/utilisateurs/[userId]/page.tsx` (new)
- `frontend/src/features/admin/admin-user-detail.tsx` (new)
- `frontend/src/features/admin/admin-users-api.ts` (détail + rôle `admin`)
- `frontend/src/features/admin/admin-user-directory.tsx` (lien vers la fiche)

### Change Log

| Date | Change |
|------|--------|
| 2026-08-06 | Créée (draft). Coquille de la fiche utilisateur + identité + gestion complète des rôles, dont `ROLE_ADMIN`. Referme #250. |
| 2026-08-06 | Implémentée. Endpoint de détail, transitions d'admin dédiées, fiche `/admin/utilisateurs/{userId}` (Identité + Rôles) et lien depuis l'annuaire. Le garde « dernier admin » du brouillon a été supprimé : inatteignable, l'invariant étant déjà porté par la règle anti-auto-modification. Gates verts. Status → review. |
