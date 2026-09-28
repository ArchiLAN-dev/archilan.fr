# Story 39.6: Suspension appliquée en exclusion temporaire

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-27

## Story

En tant que membre du staff d'ArchiLAN,
je veux qu'une suspension posée sur le site exclue temporairement le membre du serveur Discord pour la même
durée, et qu'une levée la retire,
afin qu'une suspension soit la même partout.

## Contexte

L'exclusion temporaire Discord (« timeout », `communication_disabled_until`) empêche d'écrire, de réagir et de
parler en vocal, sans retirer le membre du serveur. Discord la plafonne à **28 jours**. Décision de l'épic : une
suspension plus longue est prolongée automatiquement tant qu'elle court.

## Critères d'acceptation

1. **Suspension du site** : le compte Discord lié est exclu temporairement jusqu'à la fin de la suspension, ou
   pour 28 jours au plus. Motif dans le journal d'audit (préfixe `[archilan.fr]`). Un compte qui n'est pas sur
   le serveur n'est pas une erreur (issue « pas sur le serveur »).
2. **Prolongation** : chaque jour, l'exclusion de chaque membre encore suspendu sur le site est reposée jusqu'à
   la fin de sa suspension ou pour 28 jours au plus. Un membre en échec n'arrête pas les autres.
3. **Levée** : débannissement (39.5) **et** retrait de l'exclusion. Rien à retirer n'est pas une erreur.
4. **Issue** sur la sanction, dans le forum et sur la fiche admin : exclu temporairement, pas sur le serveur,
   sanction levée sur le serveur, plus les issues de la 39.5.
5. Même ordre et même reprise que la 39.5 : MP, puis serveur, puis forum ; rien n'est fait deux fois.
6. `composer gates` et `pnpm gates` passent.

## Mise en place côté Discord

- Permission **Exclure temporairement des membres** pour le bot (rôle du bot au-dessus des membres, comme pour
  la 39.5).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 3) - Port et adaptateur : exclusion temporaire, retrait.
- [x] **Task 2** (AC 1, 3, 4, 5) - Job de la sanction : suspension et levée.
- [x] **Task 3** (AC 2) - Prolongation quotidienne : membres suspendus (Identity), tâche planifiée.
- [x] **Task 4** (AC 4) - Libellés (forum, fiche admin).
- [x] **Task 5** (AC 6) - Gates.

## Dev Agent Record

- **Port** `DiscordServerSanctionsInterface` : `timeout` (rend faux si le membre n'est pas sur le serveur) et
  `clearTimeout` ; `TIMEOUT_CAP = 'P28D'`. Adaptateur : `PATCH /guilds/{serveur}/members/{membre}` avec
  `communication_disabled_until` (date, ou null pour retirer), motif en `X-Audit-Log-Reason` ; 404 « Unknown
  Member » = pas sur le serveur.
- **Fenêtre** `DiscordTimeoutWindow::until` : fin de la suspension, ou maintenant + 28 jours - 1 minute, en UTC ;
  commune au job de la sanction et à la prolongation.
- **Job de la sanction** : suspension -> exclusion (`timed_out` ou `not_member`) ; levée -> débannissement **et**
  retrait de l'exclusion (`lifted`, qui remplace `unbanned` pour les nouvelles levées ; les anciennes gardent
  leur libellé). Même ordre et même reprise que la 39.5.
- **Prolongation** `ExtendDiscordTimeoutsHandler`, chaque nuit à 04:30 (Paris) : exclusion reposée pour chaque
  membre encore suspendu et lié (`MemberModerationGatewayInterface::currentlySuspended`, lu dans Identity par
  `UserRepositoryInterface::findSuspendedAt` : suspendus, non bannis, non supprimés). Rien n'est stocké : reposer
  la même exclusion est sans effet de bord. Un membre en échec est journalisé
  (`moderation_server.timeout_not_extended`) sans arrêter les autres.
- **Libellés** : forum (« exclu temporairement », « pas sur le serveur », « sanction levée sur le serveur ») et
  fiche admin.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `DiscordServerSanctionsTest` (exclusion, absent du serveur), `PostModerationActionToForumHandlerTest` (suspension, plafond, absent, levée), `ExtendDiscordTimeoutsHandlerTest`, `MemberModerationGatewaySuspendedTest`, `ScheduleTest` | méthodes et classes absentes | verts |

Gates : `composer gates` (2409 tests) et `pnpm gates` (575 tests, build) verts.
