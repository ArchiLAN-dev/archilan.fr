# Story 38.22: Suivre un jeu pour être prévenu de ses nouveaux apworlds

**Status:** review
**Epic:** 38 - Santé des apworlds
**Date:** 2026-10-24
**Origine :** demande de Jean le 2026-10-24 : pouvoir marquer un jeu pour être tenu au courant de ses mises à jour
d'apworld sur le site (mise en place d'un nouvel apworld), et que ça passe par le centre de notifications.

## Story

En tant que membre connecté,
je veux suivre un jeu depuis sa page,
afin d'être notifié quand le site met en place un nouvel apworld pour ce jeu (premier apworld ou nouvelle version).

## Contexte

- Toute mise en place d'apworld (fichier admin, import GitHub, mise à jour automatique) passe par un candidat ; sa
  promotion publie `ApworldPromoted` (story 38.6), déjà écouté par les runs privées et les inscriptions (38.7). Un
  premier apworld a `previousHash` nul.
- Le centre de notifications (30.12) écrit par `Notifier::notify`, rendu par type dans
  `notification-content.ts` / `notification-center.tsx`. Les préférences par type (43.11b) viennent de
  `NotificationPreferenceService::CONFIGURABLE` ; le push d'une liste explicite (`PushMessageFactory`).

## Critères d'acceptation

1. **Suivre.** Sur `/jeux/{slug}`, un membre connecté voit un bouton « Suivre ce jeu » / « Suivi » qui bascule le
   suivi. Un visiteur ne le voit pas. `GET/PUT/DELETE /api/v1/games/{gameId}/follow` (auth) : `{following}` ;
   `PUT` et `DELETE` idempotents ; jeu inconnu 404.
2. **Stockage.** Table `game_follow` (`user_id`, `game_id`, `created_at`), unique sur le couple. Les suivis
   disparaissent avec le jeu supprimé et avec le compte effacé (pas de clé étrangère, comme les autres tables à id
   texte : le ménage est fait par `AdminGameLibrary::remove` et `DeleteAccount`).
3. **Notification.** À chaque `ApworldPromoted`, chaque membre qui suit le jeu reçoit une notification
   `game_apworld_updated` (`gameId`, `gameName`, `gameSlug`, `version` si connue, `first` si premier apworld). Texte :
   « Nouvel apworld pour {jeu} » (+ « v{version} » si connue), « {jeu} est jouable sur le site » pour un premier.
   Clic : la page du jeu.
4. **Préférences.** Le type est réglable dans `/compte/notifications` (cloche par défaut, push possible).
5. **Hors périmètre.** Pas de liste « mes jeux suivis » ni de suivi depuis le catalogue (story suivante si besoin) ;
   pas d'e-mail ni de Discord.

## Tâches

- [x] Domaine : entité `GameFollow`, repository, migration.
- [x] Application : `FollowGame` (commande, les deux sens), `GameFollowQuery`, handler
      `NotifyGameFollowersOfApworldPromotionHandler` ; `ApworldPromoted` porte la nouvelle version.
- [x] Présentation : contrôleur `GameFollowController`.
- [x] Préférences + push : `game_apworld_updated` configurable (cloche par défaut) et poussable.
- [x] Front : bouton sur la page jeu, rendu de la notification, libellé dans les préférences.
- [x] Tests : fonctionnels (suivi, notification à la promotion, pas de notification sans suivi), front.

## Dev Agent Record

- `ApworldPromoted` porte désormais `newVersion` (le tag de release du candidat, nul s'il n'en a pas) ; deux tests
  unitaires qui comparaient le message entier attendent `v2`.
- Le gate `lint:container` (env test) échoue déjà sur develop (`DiscordModerationForum`), sans lien.
- Fonctionnels (`GameFollowTest`, 9 cas dont le ménage à l'effacement du compte et à la suppression du jeu, préférences dans `FriendActivityTest`) laissés à la CI : Postgres local
  éteint.
