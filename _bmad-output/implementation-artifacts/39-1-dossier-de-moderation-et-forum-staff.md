# Story 39.1: Dossier de modération et forum staff

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-27

## Story

En tant que membre du staff d'ArchiLAN,
je veux que chaque sanction du site ouvre ou alimente le dossier du membre, reflété par un post dans le forum
staff Discord,
afin de suivre au même endroit l'historique d'un membre et d'en discuter entre nous.

## Contexte

Les sanctions du site (`AccountModerationService` : avertir, suspendre, bannir, lever) sont journalisées
(`ModerationAction`) et notifiées au membre dans le site, mais le staff n'en voit rien sur Discord. Le bot du
projet (`DISCORD_BOT_TOKEN`) n'est utilisé que pour les rôles. Voir l'épic 39 pour l'ensemble et les décisions.

## Critères d'acceptation

1. **Un dossier par membre** (`ModerationCase`) : créé à sa première sanction, rouvert à chaque nouvelle
   sanction, clos par une levée.
2. **Un post par dossier** dans le forum staff (`DISCORD_MODERATION_FORUM_ID`), créé par le bot à la première
   sanction ; les sanctions suivantes y sont postées. Chaque message donne la sanction, le motif, la fin de la
   suspension le cas échéant, le membre (nom sur le site, mention Discord si son compte est lié), le
   modérateur, et les liens vers la fiche admin du membre et vers le signalement lié.
3. **Étiquette du post** selon la dernière sanction (Avertissement, Suspension, Ban, Levée), résolue par son
   nom dans les étiquettes du forum ; une étiquette absente n'empêche rien. Un post archivé est désarchivé.
4. **Après commit, asynchrone, jamais bloquant** : la sanction est prise même si Discord est injoignable ;
   un échec passager est retenté par Messenger, un refus définitif est journalisé. Forum non configuré : rien.
5. **Fiche admin** : le panneau de modération indique le statut du dossier et le lien vers le post Discord.
6. Aucune mention ne notifie qui que ce soit (`allowed_mentions` vide).
7. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `ModerationCase`, dépôt, migration.
- [x] **Task 2** (AC 2-4, 6) - Port `ModerationForumInterface`, adaptateur Discord, message et handler.
- [x] **Task 3** (AC 4) - Envoi du message après chaque sanction réussie.
- [x] **Task 4** (AC 5) - Fiche admin (API et front).
- [x] **Task 5** (AC 7) - Gates.

## Mise en place côté Discord

1. Créer un salon **forum** pour le staff, avec les étiquettes « Avertissement », « Suspension », « Ban » et
   « Levée ».
2. Donner au bot, sur ce forum : voir le salon, créer des posts, envoyer des messages dans les fils, gérer les
   fils (désarchiver un post).
3. Renseigner `DISCORD_MODERATION_FORUM_ID` (identifiant du salon forum) dans `envs/api.env`.

## Dev Agent Record

- **Domaine** : `ModerationCase` (Community) - un par membre (index unique sur `target_user_id`), ouvert, rouvert,
  clos par une levée ; son post est attaché une fois et gardé. Migration `Version20260927120000`.
- **Déclenchement** : `AccountModerationService` construit l'action avant la transaction pour en connaître l'id,
  puis envoie `PostModerationActionToForumJob` (async) seulement si la sanction a été commitée ; un bus
  indisponible est journalisé (`moderation_forum.dispatch_failed`) sans annuler la sanction.
- **Handler** `PostModerationActionToForumHandler` : forum non configuré = rien ; ouvre ou alimente le post ;
  échec passager relancé (`ModerationForumTemporarilyUnavailableException`, reprise Messenger), refus définitif
  journalisé (`moderation_forum.not_posted`) ; l'état du dossier est gardé dans tous les cas.
- **Adaptateur** `DiscordModerationForum` : bot existant en REST (`Bot` + `DISCORD_BOT_TOKEN`), création du post
  (`POST /channels/{forum}/threads`), désarchivage et étiquette (`PATCH /channels/{post}`) puis message ;
  étiquettes résolues par nom, insensible à la casse ; `allowed_mentions` vide ; 429/5xx/réseau passagers.
- **Message** `ModerationForumMessageFactory` : sanction, motif, membre (mention si compte lié, sinon « compte
  Discord non lié »), modérateur, fin de suspension (heure de Paris), lien vers la fiche admin et vers le
  signalement lié. Le compte Discord vient de `MemberModerationGatewayInterface::discordIdOf` (Identity).
- **Fiche admin** : `case` (statut et lien du post) dans le panneau de modération ; lien « voir le post sur
  Discord ».
- **Config** : `DISCORD_MODERATION_FORUM_ID` (`.env`, `envs/api.env.example`).

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `ModerationCaseTest`, `ModerationForumMessageFactoryTest`, `PostModerationActionToForumHandlerTest`, `DiscordModerationForumTest`, `AccountModerationServiceTest` (envoi après chaque sanction commitée, rien sur un refus) | 20 erreurs (classes absentes) | 22 verts |
| `AdminAccountModerationOverviewTest` (dossier et lien, pas de dossier) | 2 échecs | verts |
| `admin-user-moderation-case.test.ts` (front) | 2 échecs | verts |
