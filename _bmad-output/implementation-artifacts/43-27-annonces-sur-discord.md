# Story 43.27: Les annonces de parties sur Discord

**Status:** review
**Epic:** 43 - Amis
**Date:** 2026-10-11

## Story

En tant que membre présent sur le Discord ArchiLAN,
je veux voir passer dans le salon dédié les parties qui cherchent des joueurs, avec une mention du rôle dédié,
afin d'en rejoindre une sans avoir à surveiller le site.

## Contexte

Idée de Jean (2026-10-11). Le serveur a déjà un salon et un rôle dédiés aux parties qui cherchent des joueurs.

- Le bot poste déjà les quêtes de la semaine (stories 41.24 et 41.26) : un message par semaine, mis à jour plutôt que
  reposté. Port `QuestAnnouncementChannelInterface` (Wallet), adaptateur `DiscordBotQuestAnnouncementChannel`,
  version désactivée `DisabledQuestAnnouncementChannel` quand `DISCORD_QUESTS_CHANNEL_ID` est vide.
- Une annonce est en ligne quand la partie est un brouillon ouvert à tous les membres, avec un message et une date de
  mise en ligne (`DbalRunListingsQuery`), qu'il reste des places et que son créateur n'est pas sanctionné.
- Elle sort de la liste par plusieurs chemins : partie lancée ou annulée, ouverture changée, places pleines,
  expiration après 14 jours sans arrivée (`ExpireRunListingsHandler`), créateur sanctionné. Elle change quand quelqu'un
  la rejoint ou que le créateur modifie le message, la date ou les places.

### Décisions

1. **Le salon reflète la page des annonces.** Plutôt que d'accrocher un appel Discord à chaque chemin (et d'en oublier
   un), une tâche de synchronisation compare les annonces en ligne aux messages déjà postés : elle poste les nouvelles,
   modifie celles qui ont changé et retire celles qui ne sont plus en ligne. Elle passe toutes les cinq minutes, et
   publier ou rejoindre une annonce la déclenche aussitôt après la transaction.
2. **Annonce terminée : message supprimé**, pour que le salon ne montre que des parties qu'on peut encore rejoindre,
   comme la page. (Choix par défaut, Jean n'a pas tranché : remplacer par une modification « complète » se fait dans
   le même code.)
3. **Une seule mention du rôle par partie.** Le premier message d'une partie mentionne le rôle ; une modification ne
   notifie pas sur Discord, et une partie retirée puis remise en ligne est repostée sans nouvelle mention. Fermer et
   rouvrir sa partie ne permet donc pas de spammer le rôle.
4. **Réglages** : `DISCORD_RUN_LISTINGS_CHANNEL_ID` et `DISCORD_RUN_LISTINGS_ROLE_ID`, vides par défaut. Salon vide :
   rien n'est posté. Rôle vide : messages postés sans mention.
5. Le message ne contient que ce que la page montre déjà à un visiteur (43.24) : titre, hôte (pseudo public), message,
   date prévue, places restantes, jeux, lien vers la page des annonces. Jamais d'amis ni d'informations de compte.

## Critères d'acceptation

1. Une annonce mise en ligne est postée dans le salon configuré en moins de cinq minutes (aussitôt dans le cas normal),
   en mentionnant le rôle configuré.
2. Le message reprend le titre, l'hôte, le message de l'annonce, la date prévue, les places restantes
   (`seatsLeftLabel`), les jeux (cinq au plus puis « +N ») et un lien vers `/communaute/parties`.
3. Quand quelqu'un rejoint, ou que le créateur modifie l'annonce, le message est modifié, sans nouvelle mention.
4. Quand l'annonce n'est plus en ligne (lancée, annulée, refermée, complète, expirée, créateur sanctionné), le message
   est supprimé.
5. Le rôle n'est mentionné qu'une fois par partie, même si elle est retirée puis remise en ligne.
6. Un message supprimé à la main sur Discord est reposté au passage suivant, sans mention.
7. Discord indisponible ou refusant l'appel : l'erreur est journalisée, le site n'est pas touché, et le passage suivant
   rattrape.
8. Salon non configuré : rien n'est posté, aucune erreur. Rôle non configuré : messages sans mention.
9. Gates verts ; tests (nouvelle annonce postée avec mention, modification sans mention, retrait supprimé, rôle
   mentionné une seule fois, message disparu reposté, Discord en panne, salon non configuré).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 5, 6) - Table de suivi (partie, id du message, empreinte du contenu, date de la mention) et
  migration.
- [x] **Task 2** (AC 2) - Contenu du message à partir de la même lecture que la page des annonces (vue visiteur).
- [x] **Task 3** (AC 1, 3, 4, 7, 8) - Port de salon (poster, modifier, supprimer), adaptateur bot et version désactivée,
  sur le modèle des quêtes ; commande de synchronisation ; tâche planifiée toutes les cinq minutes ; déclenchement
  après publication et après arrivée d'un joueur.
- [x] **Task 4** (AC 9) - Tests ; gates ; note de déploiement (deux variables, permissions du bot dans le salon :
  envoyer, gérer ses messages, mentionner le rôle).

## Dev Agent Record

- `RunListingPost` (table `personal_run_listing_post`, migration `Version20261011100000`) : message, empreinte du
  contenu, date de la mention ; la ligne survit au message.
- `SyncRunListingsToDiscord` : lit `OpenRunListingsQuery::forViewer(null)` (vue visiteur), poste, modifie ou supprime ;
  une annonce en échec n'arrête pas les autres. `RunListingsSyncResult` pour les journaux.
- Port `RunListingChannelInterface`, adaptateur `DiscordBotRunListingChannel` (embed, `allowed_mentions` limité au
  rôle configuré, la modification ne touche pas au contenu donc ne notifie pas), `DisabledRunListingChannel`,
  `RunListingChannelFactory`.
- `SyncRunListingsToDiscordMessage` routé sur `async` ; planifié toutes les 5 minutes ; envoyé après `SetRunOpenness`
  et après `JoinOpenRun`. Les autres arrivées (lien, invitation) sont rattrapées par le passage planifié.
- Le worker unique consomme `async` et `scheduler_default` l'un après l'autre : deux passages ne se chevauchent pas.
- Non testé contre le vrai Discord (pas de post sur le serveur depuis le poste de dev) : à vérifier au déploiement.
- Gates API verts (2974 tests).
