# Story 36.4: Volet jeu sur la fiche utilisateur - runs, parties, progression, comptes liés

**Status:** review
**Epic:** 36 - Espace de contrôle utilisateur (admin)
**Date:** 2026-08-07
**Dépend de :** story 36.1 (la fiche qui accueille le volet)

## Story

En tant qu'admin,
je veux voir sur la fiche d'un membre ce qu'il joue et ce qu'il a joué - ses runs personnelles, son
historique de parties, sa progression et ses comptes liés,
afin de comprendre un joueur avant d'agir sur lui, et notamment de savoir quelles runs lui appartiennent.

## Context

Trois des quatre blocs sont déjà servis par des lectures existantes ; le quatrième - les **runs
personnelles** - n'a **aucune surface admin**, ce qui est précisément le trou opérationnel décrit par
l'issue #387 (« si le propriétaire n'est pas disponible, personne ne peut administrer sa run »).

| Bloc | Lecture existante |
|---|---|
| Runs personnelles possédées et rejointes | `PersonalRunDrafts::listMine($userId)` (aujourd'hui : « Mes parties », côté membre) |
| Historique de parties terminées | `PlayerHistoryQueryInterface::fetchForUser()` - couvre déjà les sessions d'événement, les runs perso **et** les runs hebdo |
| Progression (niveau, XP, stats) | `CommunityLevelQuery::levelFor()` |
| Comptes liés Discord / Steam | `User::getDiscordId()`, `getDiscordUsername()`, `getSteamProfile()` |

Comme la 36.3, cette story est donc essentiellement de l'**assemblage** : la seule chose qui manquait
était un endroit d'où regarder.

## Acceptance Criteria

### Backend

1. **Nouvel endpoint** `GET /api/v1/admin/users/{userId}/gaming`, réservé à `ROLE_ADMIN`, renvoyant en
   un appel : progression, comptes liés, runs personnelles (possédées / rejointes) et historique de
   parties.
2. **Runs personnelles** : identifiant, titre et statut, séparées en « possédées » et « rejointes ».
   L'identifiant est nécessaire pour que la story 36.6 puisse agir dessus.
3. **Historique** : les parties terminées les plus récentes d'abord, chacune avec son jeu, son cadre
   (événement, run perso ou run hebdo) et sa date de fin. **Borné**.
4. **Progression** : niveau, XP, et les compteurs déjà calculés (runs, objectifs, checks, succès).
5. **Comptes liés** : Discord (identifiant + pseudo) et Steam. **Twitch est hors périmètre** : il n'est
   pas un compte lié au sens d'Identity mais un lien social du profil communautaire, déjà visible
   publiquement.
6. **Réutilisation stricte** : aucune nouvelle requête DBAL. Les quatre lectures existantes sont
   appelées telles quelles.
7. Le contrôleur ne fait qu'un appel Application (AC-P4) ; la query renvoie `null` sur un compte inconnu.
8. `403` non-admin, `401` anonyme, `404` inconnu.
9. Tests fonctionnels : runs possédées et rejointes distinguées, historique présent, progression,
   comptes liés, absence de fuite depuis un autre membre, codes d'accès.

### Frontend

10. Un panneau **« Jeu »** sur la fiche : progression en tête, puis comptes liés, runs personnelles et
    historique.
11. Les runs personnelles sont liées vers `/runs/{id}` ; l'historique n'est pas lié (une partie terminée
    n'a de récap que s'il a été construit - même raisonnement qu'en 36.5).
12. Panneau visible même vide, avec des états vides explicites - la règle de la fiche fixée en 36.3.
13. Dégradation locale en cas d'échec de chargement.

### Gates

14. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-8). `AdminUserGamingQuery` (Identity/Application) + endpoint.
- [x] **Task 2** (AC 9). Tests fonctionnels.
- [x] **Task 3** (AC 10-13). Panneau « Jeu ».
- [x] **Task 4** (AC 14). Gates des deux côtés.

## Dev Notes

- **Passer par `PersonalRunDrafts`, pas par `RunRepositoryInterface`.** Le dépôt est un contrat de
  **domaine** de PersonalRuns ; l'appeler depuis Identity ferait traverser une frontière de contexte
  vers un domaine étranger. Le service applicatif expose déjà exactement la bonne lecture.
- **Ne pas réimplémenter l'historique.** `PlayerHistoryQueryInterface` couvre déjà les trois formes de
  partie (événement, run perso, run hebdo) via une union. Une seconde version divergerait.
- Le payload de `listMine()` est un `array<string, mixed>` riche destiné à l'espace du membre : n'en
  projeter que ce dont la fiche a besoin, plutôt que de le relayer tel quel sur une surface admin.
- **Ne pas anticiper la 36.6.** Ce volet est en lecture seule ; relancer ou arrêter une run appartient à
  la story des actions ciblées. L'identifiant de run est exposé pour qu'elle puisse s'y brancher.

### Project Structure Notes

- API : `Identity/Application/Query/AdminUserGaming.php`, `AdminUserGamingQuery.php` (nouveaux),
  `Identity/Presentation/Controller/AdminUserGamingController.php` (nouveau).
- Front : `features/admin/admin-user-gaming.tsx` (nouveau), `features/admin/admin-users-api.ts`,
  `features/admin/admin-user-detail.tsx` (montage).

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-36-espace-de-controle-utilisateur-admin.md]
- [Source: issue #387 (run privée sans propriétaire disponible)]
- [Source: api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php]

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code).

### Completion Notes List

- Panneau « Jeu » sur la fiche : progression (niveau, XP, runs, objectifs, checks, succès), comptes liés
  Discord et Steam, runs personnelles séparées entre possédées et rejointes, et historique des parties
  terminées.
- **Les runs personnelles d'un membre sont enfin visibles côté admin.** C'est le seul des quatre blocs
  qui n'avait aucune surface - le trou opérationnel que décrit l'issue #387.
- **Aucune requête DBAL ajoutée**, comme en 36.3 : les quatre lectures existaient
  (`PersonalRunDrafts::listMine`, `PlayerHistoryQueryInterface::fetchForUser`,
  `CommunityLevelQuery::levelFor`, les champs Discord/Steam de `User`). Il manquait un endroit d'où
  regarder.
- Les runs passent par `PersonalRunDrafts` (service applicatif) et **non** par
  `RunRepositoryInterface` : ce dernier est un contrat de domaine de PersonalRuns, et l'appeler depuis
  Identity ferait traverser une frontière de contexte vers un domaine étranger - la même règle qui a
  fait vivre le volet modération dans Community en 36.2.
- Le payload riche de `listMine()` est **projeté** en trois champs plutôt que relayé tel quel : une
  surface admin n'a pas besoin de la configuration complète d'une run, mais elle a besoin de son
  identifiant, sur lequel la story 36.6 se branchera.
- `PlayerHistoryQuery` couvre déjà les trois formes de partie (événement, run perso, run hebdo) par une
  union ; elle n'est pas réimplémentée, seulement projetée, triée et bornée.
- **Twitch est volontairement hors périmètre** : ce n'est pas un compte lié au sens d'Identity mais un
  lien social du profil communautaire, déjà public sur `/joueurs/{slug}`.
- Gates : API `phpstan` (src + tests) / `php-cs-fixer` / `app:architecture:ddd` / `rector` verts,
  `phpunit` vert en run isolé ; frontend `typecheck` / `lint` / `test` / `build` verts.

### File List

- `api/src/Identity/Application/Query/AdminUserGaming.php`, `AdminUserGamingQuery.php` (new)
- `api/src/Identity/Presentation/Controller/AdminUserGamingController.php` (new)
- `api/tests/Functional/AdminUserGamingTest.php` (new, 7 cas)
- `frontend/src/features/admin/admin-user-gaming.tsx` (new)
- `frontend/src/features/admin/admin-users-api.ts` (lecture + type guards)
- `frontend/src/features/admin/admin-user-detail.tsx` (montage du panneau)

### Change Log

| Date | Change |
|------|--------|
| 2026-08-07 | Créée (draft). Assemble quatre lectures existantes ; donne enfin une vue admin des runs personnelles d'un membre. |
| 2026-08-07 | Implémentée. Endpoint de composition (aucune requête DBAL ajoutée) + panneau « Jeu ». Runs lues via le service applicatif de PersonalRuns pour ne pas traverser vers son domaine. Gates verts. Status -> review. |
