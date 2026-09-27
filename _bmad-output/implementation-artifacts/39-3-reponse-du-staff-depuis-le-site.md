# Story 39.3: Réponse du staff depuis le site

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-27

## Story

En tant que membre du staff d'ArchiLAN,
je veux répondre à un membre sanctionné depuis la page admin de son dossier,
afin qu'il reçoive la réponse sur le site et sur Discord, et que le staff voie dans le forum ce qui lui a été
envoyé.

## Contexte

La 39.2 a ouvert le sens membre vers staff : messages du membre dans le dossier (page admin) et dans le post du
forum. Les réponses partent **du site**, jamais du forum : ce qui s'écrit dans le post reste interne au staff.

Un membre banni ou suspendu ne peut pas se connecter ni lire ses notifications : il retrouve la réponse sur la
page de connexion (laissez-passer de la 39.2) et, si son compte est lié, en message privé du bot.

## Critères d'acceptation

1. **Réponse** : sur le panneau de modération de la fiche admin, un formulaire « Répondre au membre » (1 à 2000
   caractères). La réponse est enregistrée dans le dossier (auteur staff), ouvert au besoin si le membre a au
   moins une sanction ; un membre jamais sanctionné est refusé.
2. **Site** : le membre reçoit une notification « La modération t'a répondu » (lien vers son compte) ; son fil
   (espace compte ou page de connexion refusée) montre ses messages et les réponses de la modération.
3. **Message privé** : après commit, en asynchrone, le bot envoie la réponse en MP si le compte Discord est lié.
   L'issue est enregistrée sur la réponse : envoyé, impossible (MP fermés, serveur quitté), compte non lié, bot
   non configuré. Un échec passager est retenté ; un MP déjà envoyé ne l'est jamais deux fois.
4. **Forum staff** : la réponse est postée dans le post du dossier (« Réponse envoyée au membre »), avec le
   modérateur et l'issue du MP, sans changer l'étiquette.
5. **Page admin** : les réponses apparaissent dans « Messages du dossier » avec leur auteur et l'issue du MP.
6. Aucune mention ne notifie qui que ce soit.
7. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Réponse du staff dans le dossier (`fromStaff`, issue du MP), migration, commande.
- [x] **Task 2** (AC 3, 6) - Port et adaptateur de MP Discord.
- [x] **Task 3** (AC 3, 4) - Job de remise : MP puis forum, idempotent.
- [x] **Task 4** (AC 2) - Notification et fil du membre avec les réponses.
- [x] **Task 5** (AC 1, 5) - Route admin et panneau (formulaire, issue du MP).
- [x] **Task 6** (AC 7) - Gates.

## Dev Agent Record

- **Domaine** : `ModerationCaseMessage::fromStaff`, `recordDirectMessage` (la première issue reste : un MP n'est
  jamais renvoyé), colonne `discord_dm_status` (migration `Version20260927220000`) ; notification
  `moderation_reply`.
- **Commande** `ReplyToMember::reply` -> `ReplyToMemberOutcome` (`Sent`, `Invalid`, `NotSanctioned`) : dossier
  ouvert au besoin pour un membre sanctionné ; après le flush, notification du membre et
  `DeliverStaffReplyJob` (un bus indisponible est journalisé, le membre lit quand même la réponse sur le site).
- **Remise** `DeliverStaffReplyHandler` : MP d'abord (compte non lié, bot sans jeton, envoyé, ou impossible et
  journalisé `moderation_reply.dm_not_sent`), issue enregistrée et commitée **avant** le forum ; échec passager
  du MP relancé sans rien enregistrer ; puis carte « Réponse envoyée au membre » (modérateur, issue du MP) via
  `ModerationForumDelivery`.
- **Discord** : `DiscordBotRest` (appel REST authentifié et carte commune, `allowed_mentions` vide) extrait de
  l'adaptateur du forum ; `DiscordMemberDirectMessages` (`POST /users/@me/channels` puis message). Le MP ne nomme
  pas le modérateur : c'est l'équipe qui parle.
- **Lectures** : le fil du membre (compte ou laissez-passer) contient les réponses (`author`), sans nom de
  modérateur ; le panneau admin montre `discordDm` sur chaque message.
- **Route** `POST /api/v1/admin/community/accounts/{userId}/moderation/replies` (admin) : 201, 422, 403.
- **Front** : formulaire « Répondre au membre » et issue du MP dans le panneau admin ; réponses de la modération
  mises en avant dans le fil du membre ; notification « La modération t'a répondu » vers `/compte`.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `ModerationCaseMessageTest` (réponse, issue unique), `ReplyToMemberTest`, `DeliverStaffReplyHandlerTest`, `DiscordMemberDirectMessagesTest`, `ModerationReplyTest` (fonctionnel) | 17 erreurs, 5 échecs | verts |
| `notification-center.test.ts`, `moderation-contact-*.test.*`, `admin-user-moderation-case.test.ts` | 5 échecs | verts |

Gates : `composer gates` (2373 tests) et `pnpm gates` (574 tests, build) verts.

Note : `created_at` est à la seconde ; deux messages de la même seconde n'ont pas d'ordre garanti entre eux
(le test fonctionnel recule le message du membre d'une minute).
