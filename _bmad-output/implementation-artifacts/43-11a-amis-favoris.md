# Story 43.11a: Amis favoris

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-10
**Découpée de:** 43.11 (partie favoris et tri, sans notification)

## Story

En tant que joueur,
je veux marquer quelques amis comme favoris,
afin de les retrouver en tête de mes listes d'amis.

## Critères d'acceptation

1. Un ami (amitié acceptée) peut être marqué « favori » (étoile) depuis `/compte/amis` ou depuis son profil, et
   retiré de la même façon. Marquer un non-ami est refusé (422). Maximum 15 favoris (422 au-delà).
2. Les favoris passent en tête : dans « Mes amis » de `/compte/amis`, dans l'annuaire filtré sur les amis, et dans
   « Mes amis en ce moment » (43.5), en gardant l'ordre existant à l'intérieur de chaque groupe.
3. Être mis en favori n'est **pas** visible de l'ami concerné : l'information n'apparaît que dans les réponses
   destinées à celui qui a mis l'étoile.
4. Fin d'amitié ou blocage : le favori est supprimé dans les deux sens (une nouvelle amitié repart sans étoile).
5. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Domaine/Migration** : entité `FriendFavorite`, table `community_friend_favorite (user_id, favorite_user_id)`
      unique. Pas de drapeau sur `community_friendship` (ligne canonique partagée par les deux parties).
- [x] **Application** : `FriendshipService::favorite` / `unfavorite` (ami requis, plafond 15, idempotent) ;
      `relationship()` expose `favorite` ; `friends()` expose `isFavorite` et trie les favoris en tête ;
      `removeFriendship` et `block` suppriment les favoris dans les deux sens.
- [x] **Application** : annuaire filtré sur les amis et `FriendsNowQuery` : favoris en tête, `isFavorite`.
- [x] **Présentation** : `POST` / `DELETE /api/v1/community/profiles/{slug}/favorite`.
- [x] **Front** : étoile sur `/compte/amis` et sur le profil, étoile dans « Mes amis en ce moment ».
- [x] Tests (plafond, non-ami, invisibilité côté ami, suppression par fin d'amitié et blocage, tri) et gates.

## Dev Notes

- `FriendFavorite` (`community_friend_favorite`, migration `Version20261010100000`), plafond
  `FriendFavorite::MAX_PER_USER` = 15. `FriendshipService::favorite` renvoie `not_friend` / `limit` (422 côté
  contrôleur, codes `not_friend` / `favorite_limit`) ; re-étoiler un favori existant reste `ok` même au plafond.
- `relationship()` porte désormais `favorite` (toujours `false` hors amitié acceptée) : seul le viewer y lit sa
  propre étoile, jamais celle que l'ami a posée sur lui.
- Tri « favoris en tête » partout par partition stable : `friends()` (ordre existant), annuaire `friendsOnly`
  (appliqué après la remontée des membres en direct de 30.39, donc favoris d'abord, live en tête de chaque groupe),
  `FriendsNowQuery` (playing : favori puis nom ; recent : favori puis plus récent).
- `removeFriendship` (fin d'amitié ou demande annulée) et `block` appellent `removeBetween` : l'étoile disparaît dans
  les deux sens.
- Front : `FavoriteFriendButton` (compact sur `/compte/amis`, bouton plein sur le profil), étoile pleine à côté du
  nom dans `FriendIdentity` quand `isFavorite` (donc aussi dans « Mes amis en ce moment »).
- Au passage : `friends-now-card.test.tsx` (43.5) échouait juste après minuit (« hier » au lieu de « il y a 2 h ») ;
  l'attendu passe par `timeLabel`.
- Tests : `FriendFavoritesTest` (tri, invisibilité côté ami, non-ami, plafond, fin d'amitié et blocage),
  `FriendsNowTest::testStarredFriendsComeFirst`, `favorite-friend-button.test.tsx`.
