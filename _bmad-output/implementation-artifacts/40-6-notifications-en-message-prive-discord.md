# Story 40.6: Les notifications en message privé Discord

**Status:** review
**Epic:** 40 - BK et push
**Date:** 2026-10-11

## Story

En tant que membre qui a relié son compte Discord,
je veux recevoir en message privé du bot les notifications de mes parties,
afin d'être prévenu là où je suis déjà, même sans le site ouvert ni le push activé.

## Contexte

Demande de Jean (2026-10-11) : le créateur d'une partie est prévenu sur Discord quand un joueur rejoint ou ajoute des
jeux, et les joueurs quand la partie démarre, si leur Discord est relié.

- Les notifications passent par `NotificationService::notify` : cloche, puis push navigateur (40.2) selon la
  préférence du membre par type (`NotificationChannel` : `bell_push`, `bell`, `none`, story 43.11b).
- Le bot envoie déjà des messages privés pour la modération (`MemberDirectMessageInterface`, stories 39.3 et 39.4),
  avec des erreurs transitoires (limite de débit) distinguées des refus.
- Le compte relié est `User.discordId`. Un membre peut refuser les messages privés des membres du serveur : Discord
  refuse alors l'envoi.

### Décisions

1. **Discord est un canal en plus, pas une valeur de plus.** Chaque type réglable garde son choix cloche/push et gagne
   une case « Aussi sur Discord », proposée seulement aux membres dont le Discord est relié.
2. **Par défaut** : cochée pour les notifications de partie (`run_joined`, jeux choisis, démarrage, fin, story 43.28 ;
   `run_invitation`, `run_nudge`, `slot_unblocked`), décochée pour le reste. Un type réglé sur `none` n'envoie rien
   nulle part.
3. **Envoi asynchrone, après la transaction** (un job par notification), comme le push : la cloche ne dépend jamais de
   Discord. Erreur transitoire : nouvelle tentative par Messenger. Refus (messages privés fermés, compte quitté) :
   journalisé, sans nouvelle tentative.
4. Le message reprend le texte du push (`PushMessageFactory`), avec le lien vers le site ; un type sans texte de push
   reçoit un texte dédié ou n'est pas proposé sur Discord.

## Critères d'acceptation

1. Un membre au Discord relié qui laisse la case cochée reçoit la notification en message privé du bot, avec le lien.
2. Un membre sans Discord relié ne voit pas la case et rien n'est envoyé.
3. Décocher la case, ou régler le type sur « rien », arrête les messages privés de ce type.
4. Discord indisponible ou membre aux messages privés fermés : la cloche et le push ne changent pas ; erreur
   journalisée ; nouvelle tentative seulement pour une erreur transitoire.
5. Sans jeton de bot, rien n'est envoyé et rien ne casse.
6. Les préférences (page des notifications du compte) montrent la case pour chaque type réglable.
7. Gates verts ; tests (envoi, case décochée, pas de Discord relié, type à « rien », refus Discord sans nouvelle
   tentative, erreur transitoire relancée, bot non configuré).

## Tasks / Subtasks

- [x] **Task 1** (AC 3, 6) - Préférence « aussi sur Discord » par type (migration), défauts, API des préférences.
- [x] **Task 2** (AC 1, 4, 5) - Job d'envoi, port de message privé (réutiliser l'adaptateur de la modération), textes.
- [x] **Task 3** (AC 2, 6) - Front : case dans les préférences, visible si le Discord est relié.
- [x] **Task 4** (AC 7) - Tests ; gates ; note de déploiement.

## Dev Agent Record

- `NotificationPreference.discord` (nullable, null = défaut du type), migration `Version20261011120000`.
- `NotificationPreferenceService` : `DISCORD_BY_DEFAULT` (invitation, sortie de BK, relance, et les quatre moments de
  la 43.28), `discordFor()` (seulement un type réglable qui a un texte de push), `chooseDiscord()` ; `forUser()` rend
  `discord: null` tant que le Discord du membre n'est pas relié.
- Le PUT des préférences accepte `{"discord": bool}` en plus de `{"channel": ...}`.
- `NotificationService::notify` envoie `SendDiscordNotificationJob` (transport `async`) ; le handler lit le texte de
  push, l'identifiant Discord du membre, et envoie par `DiscordNotificationDirectMessages` (ouverture du salon privé
  comme la modération, embed avec lien vers le site, aucune mention). Erreur passagère : relance Messenger ; refus :
  journalisé.
- **Interrupteur** `DISCORD_NOTIFICATION_DMS`, coupé par défaut (même principe que `DISCORD_MODERATION_SYNC`) : un
  déploiement ne se met pas à écrire aux membres tout seul.
- Front : case « Aussi en message privé sur Discord » sous chaque type, si Discord est relié et que le type n'est pas
  sur « Rien ».
- Non essayé contre le vrai Discord depuis le poste de dev.
- Gates verts : API 2988 tests, front 1035 tests.
