# Story 41.26: Annonce des quêtes par le bot, et à la main

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-07

## Story

En tant qu'admin,
je veux que les quêtes de la semaine soient annoncées sur Discord par le bot du site, et pouvoir lancer l'annonce
moi-même,
afin de tester l'annonce, la refaire après une panne, et ne pas avoir de doublon dans le salon.

## Contexte

Suite de la 41.24 (webhook entrant), demandée par Jean (2026-10-07) avant toute mise en service :
`DISCORD_QUESTS_WEBHOOK_URL` n'a encore été renseignée nulle part. Le bot du projet parle déjà au nom du site pour
la modération (épic 39) : l'annonce passe par lui, dans un salon configuré par son identifiant. Le bot doit pouvoir
« Envoyer des messages » et « Intégrer des liens » dans ce salon.

Le client REST du bot (`DiscordBotRest`, `DiscordRestFailure`) vit dans `Community\Infrastructure` ; le validateur DDD
interdit à Wallet de dépendre de l'Infrastructure d'un autre contexte : la partie générique passe dans
`Shared\Infrastructure\Http`, la carte des messages de modération reste dans Community.

## Critères d'acceptation

1. **Par le bot** : l'annonce est postée par le bot dans le salon `DISCORD_QUESTS_CHANNEL_ID` (vide, ou pas de
   `DISCORD_BOT_TOKEN` : aucune annonce). `DISCORD_QUESTS_WEBHOOK_URL` disparaît. Aucune mention n'est résolue.
2. **Un message par semaine** : l'identifiant du message de la semaine est gardé ; une nouvelle annonce de la même
   semaine modifie ce message au lieu d'en poster un autre. S'il a été supprimé sur Discord, elle en poste un neuf.
3. **À la main** : `POST /api/v1/admin/quest-weeks/{semaine}/announce-discord` (admin) lance l'annonce tout de suite
   et dit si le message a été posté ou mis à jour ; refus clairs si Discord n'est pas configuré (409), si la semaine
   n'a pas de quête (409), si Discord refuse (502). Commande `app:quests:announce-discord [semaine]`, semaine en
   cours par défaut.
4. **Admin** : bouton « Annoncer sur Discord » sur la semaine en cours de la page Quêtes hebdo > Semaines, avec une
   modale de confirmation ; absent quand Discord n'est pas configuré.
5. L'annonce automatique du lundi (41.17, 41.24) ne change pas de moment ; une panne reste notée dans les logs.
6. Gates verts ; tests (bot : poste, met à jour, reposte après suppression, aucune mention ; action manuelle ; refus).

## Tasks / Subtasks

- [x] **Task 1** - `DiscordBotRest` et `DiscordRestFailure` dans Shared, carte de modération dans Community.
- [x] **Task 2** (AC 1, 2, 5) - Port `publish()`, `DiscordBotQuestAnnouncementChannel`, message gardé par semaine,
  `AnnounceQuestsOnDiscord` (commande appelée par le job), variables d'environnement ; tests.
- [x] **Task 3** (AC 3, 4) - Route admin, commande console, bouton et modale ; tests.
- [x] **Task 4** (AC 6) - Gates.

## Dev Agent Record

- Shared : `DiscordBotRest` et `DiscordRestFailure` déplacés dans `Shared\Infrastructure\Http` ; la carte des
  messages de modération devient `Community\Infrastructure\Adapter\DiscordModerationCard`.
- Wallet : port `QuestAnnouncementChannelInterface` (`isEnabled()`, `publish(annonce, ?messageId): messageId`),
  `DiscordBotQuestAnnouncementChannel` (POST, PATCH du message de la semaine, POST de nouveau sur 404),
  `QuestAnnouncementDeliveryException` ; réglage `quests_discord_message` (`{semaine}|{id}`) ;
  `AnnounceQuestsOnDiscord` (commande, résultat `QuestDiscordAnnouncement` posted / updated, refus 409 / 404 / 502),
  appelée par le job du lundi et par `POST /api/v1/admin/quest-weeks/{semaine}/announce-discord` ;
  `app:quests:announce-discord [semaine]` ; `discordEnabled` dans la vue admin ; `QuestAnnouncement::describe()`.
- Variables : `DISCORD_QUESTS_WEBHOOK_URL` retirée, `DISCORD_QUESTS_CHANNEL_ID` ajoutée (avec `DISCORD_BOT_TOKEN`).
- Front : bouton « Annoncer sur Discord » et modale sur la semaine en cours, message selon le résultat.
- Tests : unitaires du canal (poste, met à jour, reposte, 403 sans jeton, configuration), fonctionnels (route admin,
  refus, droits, commande console, job), front (bouton, garde, appel). `composer gates` (2 799) et `pnpm gates`
  (856) verts.
