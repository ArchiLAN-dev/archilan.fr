# Story 39.5: Ban et levée appliqués sur le serveur Discord

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-27

## Story

En tant que membre du staff d'ArchiLAN,
je veux qu'un ban posé sur le site bannisse aussi le membre du serveur Discord, et qu'une levée le débannisse,
afin qu'une sanction soit la même partout sans la reporter à la main.

## Contexte

Les sanctions du site passent déjà par le job de la 39.1 (dossier, MP du bot en 39.4, forum). Le serveur est
`DISCORD_GUILD_ID`, le bot est celui du projet. La 39.4 impose l'ordre : le MP de sanction part **avant** le
ban, sinon le bot ne partage plus de serveur avec le membre et ne peut plus lui écrire.

## Critères d'acceptation

1. **Ban du site** : le compte Discord lié est banni du serveur, sans effacer ses messages. Le motif est porté
   dans le journal d'audit du serveur, préfixé `[archilan.fr]` pour que la 39.7 reconnaisse les bans posés par
   le site.
2. **Levée** : le compte est débanni. Un compte qui n'était pas banni n'est pas une erreur.
3. **Avertissement et suspension** : rien sur le serveur (la suspension relève de la 39.6).
4. **Issue enregistrée** sur la sanction : banni, débanni, compte non lié, bot ou serveur non configuré, échec
   (permission manquante, rôle du bot trop bas). Donnée dans le forum et sur la fiche admin. Un échec passager
   est retenté ; une action déjà appliquée ne l'est jamais deux fois.
5. **Ordre** : le MP de sanction (39.4) part avant le ban.
6. `composer gates` et `pnpm gates` passent.

## Mise en place côté Discord

- Permission **Bannir des membres** pour le bot.
- Rôle du bot **au-dessus** des rôles des membres dans la liste des rôles (Discord refuse de bannir un membre
  dont le rôle le plus haut est au-dessus de celui du bot).

## Tasks / Subtasks

- [x] **Task 1** (AC 4) - Issue côté serveur sur la sanction, migration.
- [x] **Task 2** (AC 1, 2) - Port et adaptateur (ban, débannissement, journal d'audit).
- [x] **Task 3** (AC 1 à 5) - Job de la sanction : MP, puis serveur, puis forum.
- [x] **Task 4** (AC 4) - Fiche admin : issue du MP et du serveur sur chaque sanction.
- [x] **Task 5** (AC 6) - Gates.

## Dev Agent Record

- **Domaine** : `ModerationAction::recordServerSanction` et `SERVER_*` (banni, débanni, compte non lié, non
  configuré, échec), première issue gardée ; migration `Version20260928120000`.
- **Port** `DiscordServerSanctionsInterface` (`AUDIT_PREFIX = '[archilan.fr]'`, repris par la 39.7) ; adaptateur
  `DiscordServerSanctions` : `PUT /guilds/{serveur}/bans/{membre}` avec `delete_message_seconds: 0`,
  `DELETE` pour la levée (404 « Unknown Ban » = rien à lever, pas une erreur), motif URL-encodé dans
  `X-Audit-Log-Reason`. `DiscordBotRest` accepte des en-têtes et `DiscordRestFailure` porte le code HTTP.
- **Job de la sanction** : MP (39.4), issue commitée ; puis serveur pour un ban ou une levée, issue commitée ;
  puis forum (« Serveur Discord »). Échec passager du serveur relancé
  (`DiscordServerTemporarilyUnavailableException`) sans renvoyer le MP ; le forum attend l'issue du serveur.
  Refus définitif journalisé (`moderation_server.not_applied`).
- **Fiche admin** : chaque sanction de l'historique porte `discordDm` et `discordServer`, affichés en une ligne
  « Discord : ... ».

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `ModerationActionDirectMessageTest` (issue serveur), `DiscordServerSanctionsTest`, `PostModerationActionToForumHandlerTest` (ban après le MP, levée, avertissement, non lié, permission, retry) | interface absente | verts |
| `AdminAccountModerationOverviewTest` (clés Discord), `admin-user-moderation-case.test.ts` | 1 échec chacun | verts |

Gates : `composer gates` (2399 tests) et `pnpm gates` (575 tests, build) verts.
