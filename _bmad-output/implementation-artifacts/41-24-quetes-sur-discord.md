# Story 41.24: Les quêtes de la semaine sur Discord

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant que membre de la communauté sur Discord,
je veux voir passer les quêtes de la semaine dans un salon,
afin de les connaître sans aller sur le site.

## Contexte

La 41.17 notifie les membres actifs sur le site quand les quêtes de la semaine sortent. Beaucoup de membres vivent
surtout sur le Discord. Piste proposée par Claude, retenue par Jean (2026-10-06, « Go faire les stories que tu m'as
donné »).

## Critères d'acceptation

1. Quand la semaine est annoncée (41.17 : une fois, seulement si elle a des quêtes), un message part dans un salon
   Discord par un webhook entrant : les quêtes (titre, objectifs en clair, récompense), le coffre s'il y en a un, le
   gain maximal, la date du prochain renouvellement et un lien vers le portefeuille.
2. Réglage `DISCORD_QUESTS_WEBHOOK_URL` ; vide = pas de message (comme les alertes staff de la 38.2).
3. Le message part en tâche de fond (worker), jamais dans la tâche horaire ; un échec est journalisé, sans réessai
   ni effet sur l'annonce du site. Aucune mention n'est résolue (`allowed_mentions` vide) et l'URL, qui est le
   secret, ne fuit dans aucun log.
4. Gates verts ; tests (contenu du message, réglage vide, déclenchement à l'annonce).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 3) - Port, adaptateur webhook, adaptateur désactivé, tâche de fond ; tests.
- [x] **Task 2** (AC 1) - Déclenchement à l'annonce ; tests.
- [x] **Task 3** (AC 2, 4) - Réglage, gates.

## Dev Agent Record

- Port `QuestAnnouncementChannelInterface` ; adaptateurs `DiscordWebhookQuestAnnouncementChannel` (embed, `allowed_mentions`
  vide, l'URL jamais dans un message d'erreur) et `DisabledQuestAnnouncementChannel`, choisis par
  `QuestAnnouncementChannelFactory` selon `DISCORD_QUESTS_WEBHOOK_URL` (comme les alertes staff de la 38.2).
- `AwardWeeklyQuests::announce()` met `AnnounceQuestsOnDiscordJob` sur le transport `async` ; le handler relit la semaine
  servie, décrit les objectifs en clair (cibles nommées) et poste ; un échec est journalisé
  (`wallet.quests.discord_failed`), sans réessai.
- Réglage documenté dans `api/.env`, `envs/api.env.example` et `.env.prod.example`.
- Non testé contre un vrai Discord : créer un webhook dans le salon voulu (Paramètres du salon > Intégrations >
  Webhooks) et le mettre dans `DISCORD_QUESTS_WEBHOOK_URL`.
- `composer gates` vert (2 786 tests) ; aucun changement front.
