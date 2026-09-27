# Epic 39: Modération synchronisée avec Discord

**Statut :** validé le 2026-09-27 ; 39.1 à 39.7 livrées, 39.9 (durcissement) en revue
**Date :** 2026-09-27
**Origine :** demande de Jean le 2026-09-27 - suivre chaque sanction dans un forum staff sur Discord, échanger
avec le membre sanctionné (« OK pour le ban, mais j'aimerais être remboursé »), et appliquer les sanctions des
deux côtés.

## Objectif

Qu'une sanction soit **la même partout**, **suivie dans un dossier**, et que le membre puisse **en parler** :

1. chaque membre sanctionné a un **dossier de modération**, reflété par un post dans un **forum staff** Discord ;
2. le membre écrit à la modération depuis le site ou en répondant au MP du bot ; ses messages arrivent dans le
   dossier (page admin) et dans le post du forum ;
3. le staff discute librement dans le post (interne), et répond au membre depuis la page admin du site ;
4. la sanction s'applique aussi sur le serveur Discord, et un ban posé sur Discord est répercuté sur le site.

## Existant

- **Modération du site** (epic 30) : `AccountModerationService` - `warn`, `suspend` (jusqu'à une date), `ban`,
  `lift` ; journal `ModerationAction` ; notification dans le site (`Notifier`, après commit) ; les admins ne sont
  jamais sanctionnés.
- **Bot Discord existant** : application Discord du projet, utilisée par l'API en REST avec
  `DISCORD_BOT_TOKEN` (`DiscordBotClient` : attribution et retrait de rôles), serveur `DISCORD_GUILD_ID`,
  comptes liés par OAuth (`discordId`). Il **agit** mais **n'écoute pas** : aucun processus ne reste connecté.

## Principes

- **Même bot, pas de nouveau service.** L'API appelle Discord en REST avec le bot existant. Ce qu'il faut
  « écouter » l'est par des vérifications planifiées : réponses aux MP (chaque minute, dossiers ouverts
  seulement) et bans du serveur (toutes les 5 minutes).
- **Le site reste la source des sanctions** ; Discord en est le reflet. Un ban posé sur Discord passe par le
  site, qui l'applique puis le rediffuse.
- **Les réponses au membre partent du site** (page admin du dossier) : ce qui s'écrit dans le post du forum reste
  interne au staff, sans risque d'envoyer une discussion interne au membre.
- **Effets Discord après commit, asynchrones, jamais bloquants** : Discord injoignable = sanction prise quand
  même, échec journalisé et signalé, message retenté par Messenger.
- **Pas de boucle** : les actions de notre bot ne sont jamais réimportées. **Idempotence** partout.

## Stories

### 39.1 Dossier de modération et forum staff

- Un **dossier par membre** (`ModerationCase`) : sanctions et messages, statut ouvert ou clos. Une nouvelle
  sanction rouvre le dossier.
- Le bot crée **un post par dossier** dans le forum staff (`DISCORD_MODERATION_FORUM_ID`), avec une étiquette
  par type de sanction, puis y poste chaque sanction (motif, durée, modérateur, liens vers le signalement et vers
  le dossier sur le site). Levée comprise.
- Page admin du dossier : historique des sanctions et des messages.
- Permissions du bot sur le forum : voir, créer des posts, envoyer des messages dans les fils.

### 39.2 Le membre écrit à la modération depuis le site

- Dans son espace compte, sur sa sanction : « Contacter la modération ». Marche sans compte Discord et même
  banni du serveur.
- Un membre banni ou suspendu ne peut plus se connecter au site : un laissez-passer de contact (cookie signé,
  1 h, limité à cette route) est posé quand ses identifiants sont validés, par mot de passe ou par Discord.
- Le message est enregistré dans le dossier (page admin) et posté dans le post du forum par le bot.

### 39.3 Réponse du staff depuis le site

- Sur la page admin du dossier, le staff répond au membre : la réponse est enregistrée, notifiée au membre dans
  le site, envoyée en MP par le bot si son compte est lié, et postée dans le forum (« Réponse envoyée au
  membre »).

### 39.4 Message privé au membre sanctionné, et ses réponses

- À chaque sanction, le bot envoie un MP au membre (sanction, motif, durée, comment contester). MP impossible
  (compte non lié, MP fermés) : indiqué dans le forum ; la notification du site reste la trace principale.
- Les **réponses du membre au MP** sont relevées chaque minute (dossiers ouverts seulement), enregistrées dans
  le dossier et postées dans le forum.

### 39.5 Ban et levée appliqués sur le serveur

- Ban du site : ban Discord (motif en `X-Audit-Log-Reason`, préfixé pour être reconnu par 39.7). Levée :
  débannissement. Compte non lié : rien côté Discord, indiqué dans le forum.
- Permission : **Bannir des membres**, rôle du bot au-dessus des membres.

### 39.6 Suspension appliquée en exclusion temporaire

- Exclusion temporaire Discord (`communication_disabled_until`), plafonnée à 28 jours par Discord : prolongée
  par une tâche planifiée tant que la suspension court. Levée : exclusion retirée.
- Permission : **Exclure temporairement des membres**.

### 39.7 Ban posé sur Discord, répercuté sur le site

- Toutes les 5 minutes, liste des bans du serveur : un ban qui n'est pas de notre bot, sur un compte lié,
  bannit le compte du site (acteur « Discord », motif et auteur repris du journal d'audit) et ouvre ou rouvre
  son dossier. Un débannissement sur Discord lève un ban venu de Discord, jamais un ban posé depuis le site.
- Compte non lié : rien sur le site, mention dans le forum. Admin du site : jamais sanctionné, signalé.
- Permissions : **Bannir des membres** (lire les bans), **Voir les logs du serveur** (auteur du ban).

### 39.9 Durcissement avant mise en prod

- Issue de la review globale : interrupteur `DISCORD_MODERATION_SYNC` (désactivé par défaut), bans existants à
  l'activation montrés une fois au staff et jamais appliqués, levée en attente jamais défaite, sanction dépassée
  non appliquée, MP non bloquant, relevés en async, plafond des MP relevés, ergonomie.

### 39.8 (optionnel) Exclusions temporaires posées sur Discord

- Même principe pour les exclusions temporaires ; plus coûteux (Discord ne les liste pas, il faut parcourir les
  membres). À décider après 39.7.

## Décisions prises (2026-09-27)

- Forum staff via le **bot existant**, plutôt qu'un webhook.
- Membre → staff : **depuis le site et en MP au bot**.
- Staff → membre : **depuis la page admin du site**.
- **Un dossier par membre**, rouvert à chaque sanction.
- **Pas de nouveau service** : vérifications planifiées depuis l'API.
- **Suspension de plus de 28 jours** : l'exclusion temporaire Discord est prolongée automatiquement tant que la
  suspension court.
- **Ban Discord d'un compte non lié** : ignoré côté site (mention dans le forum).
- **Ban Discord** : aucun message effacé.

## Ordre proposé

39.1 → 39.2 → 39.3 → 39.4 → 39.5 → 39.6 → 39.7, puis 39.8 si utile. 39.1 à 39.3 donnent déjà le forum et la
conversation depuis le site, sans aucune permission de modération pour le bot.
