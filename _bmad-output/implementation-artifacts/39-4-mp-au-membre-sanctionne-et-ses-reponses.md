# Story 39.4: Message privé au membre sanctionné, et ses réponses

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-27

## Story

En tant que membre sanctionné dont le compte Discord est lié,
je veux être prévenu de ma sanction en message privé par le bot, et pouvoir lui répondre directement,
afin de ne pas dépendre du site (où je ne peux plus me connecter si je suis banni) pour savoir et contester.

En tant que membre du staff,
je veux que ces réponses arrivent dans le dossier et dans le post du forum,
afin de tout suivre au même endroit.

## Contexte

La 39.3 envoie déjà en MP les réponses du staff. Le bot n'écoute pas Discord (pas de connexion permanente,
décision de l'épic) : les réponses au MP sont **relevées** par une tâche planifiée, chaque minute, pour les
dossiers ouverts seulement.

## Critères d'acceptation

1. **MP à chaque sanction** (avertissement, suspension, ban, levée) : sanction, motif, fin de la suspension,
   comment contester (répondre au MP, ou écrire depuis le site). L'issue du MP est enregistrée sur la sanction
   (envoyé, impossible, compte non lié, bot non configuré) et donnée dans le forum. Un MP n'est jamais envoyé
   deux fois, même si le job est relancé.
2. Le dossier est tenu à jour à chaque sanction, **que le forum soit configuré ou non** (il porte désormais le
   canal de MP du membre).
3. **Relevé des réponses** : chaque minute, pour chaque dossier ouvert dont le canal de MP est connu, les
   messages écrits par le membre depuis le dernier relevé sont enregistrés dans le dossier (source « MP
   Discord ») puis postés dans le forum. Les messages du bot sont ignorés. Un message n'est jamais importé deux
   fois ; le curseur avance même quand il n'y a rien à importer.
4. Le relevé ne s'arrête jamais sur un dossier en échec : l'erreur est journalisée et le dossier suivant est
   traité ; le relevé suivant reprend là où il s'était arrêté.
5. Un message vide (pièce jointe seule) est enregistré avec le lien de la pièce jointe ; un message trop long est
   tronqué à 2000 caractères.
6. Le panneau admin et le fil du membre montrent l'origine d'un message (site ou MP Discord).
7. Aucune mention ne notifie qui que ce soit.
8. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Issue du MP sur la sanction ; handler de la 39.1 : dossier toujours tenu, MP puis
  forum.
- [x] **Task 2** (AC 3) - Canal de MP et curseur sur le dossier ; `send` renvoie le canal et le message.
- [x] **Task 3** (AC 3, 4, 5) - Lecture des MP (port, adaptateur) ; relevé planifié et son handler.
- [x] **Task 4** (AC 6) - Origine des messages (API et front).
- [x] **Task 5** (AC 8) - Gates.

## Dev Agent Record

- **Domaine** : `ModerationAction::recordDirectMessage` (seule donnée remplie après coup sur la ligne d'audit,
  une fois) ; `ModerationCase` porte le canal de MP et un curseur (`attachDirectMessageChannel` : le curseur part
  du premier message du bot et n'est jamais reculé par un suivant ; `advanceDirectMessageCursor` : comparaison
  numérique des snowflakes, par longueur puis ordre) ; `ModerationCaseMessage::fromMemberDirectMessage` (source
  `discord_dm`, id Discord unique, texte tronqué à 2000). Migration `Version20260928090000`.
- **Envoi** : `MemberDirectMessenger` (commun à la 39.3) rend l'issue du MP et donne au dossier son canal.
  `MemberDirectMessageInterface::send` renvoie `SentDirectMessage` (canal, message).
- **Sanction** (handler de la 39.1) : le dossier est tenu à jour même sans forum ; MP d'abord
  (`forSanctionDirectMessage` : sanction, motif, fin de suspension en heure de Paris, comment contester ou, pour
  une levée, retour sur le site), issue commitée avant le forum, puis carte du forum avec « Message privé
  Discord ». Le test « forum non configuré = rien » de la 39.1 devient « le dossier et le MP ont lieu quand
  même ».
- **Relevé** `PollModerationDirectMessagesHandler`, planifié chaque minute (`Schedule`) : dossiers ouverts avec
  un canal ; `GET /channels/{canal}/messages?after={curseur}&limit=100` ; messages du bot ignorés mais le
  curseur avance ; un message vide ignoré, une pièce jointe gardée par son lien ; doublon écarté par l'id
  Discord ; chaque réponse part au forum (`PostModerationMessageToForumJob`, « Écrit depuis : un MP au bot »). Un
  dossier en échec est journalisé (`moderation_dm.poll_failed`) sans arrêter les autres.
- **Discord** : `DiscordBotRest::requestList` pour les réponses en liste. L'intent « Message Content » ne concerne
  pas les MP adressés au bot : rien à activer.
- **Lectures et front** : `source` dans le fil du membre (« Envoyé en MP au bot ») et dans le panneau admin
  (« en MP au bot »).
- **Pour la 39.5** : le ban Discord devra passer **après** ce MP, sinon le bot ne partage plus de serveur avec
  le membre et ne peut plus lui écrire.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `ModerationCaseTest` (canal, curseur), `ModerationCaseMessageTest` (réponse au bot), `ModerationActionDirectMessageTest` | méthodes absentes | verts |
| `PostModerationActionToForumHandlerTest` (MP, sans forum, retry), `DeliverStaffReplyHandlerTest` (canal), `PollModerationDirectMessagesHandlerTest`, `DiscordMemberDirectMessagesTest` (envoi, lecture) | erreur fatale (signature du port) | verts |
| `ScheduleTest` (relevé chaque minute) | - | vert |
| `moderation-contact-*.test.*` (origine) | 3 échecs | verts |

Gates : `composer gates` (2388 tests) et `pnpm gates` (574 tests, build) verts.
