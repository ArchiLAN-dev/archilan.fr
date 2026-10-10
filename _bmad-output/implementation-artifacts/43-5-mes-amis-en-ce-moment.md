# Story 43.5: Encart « Mes amis en ce moment »

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.6 (visibilité de la présence)

## Story

En tant que joueur connecté,
je veux voir d'un coup d'œil lesquels de mes amis jouent en ce moment, et à quoi,
afin d'aller suivre leur partie ou de leur proposer d'en lancer une ensemble.

## Contexte

La présence dérivée existe (story 30.14) : `CommunityPresenceQueryInterface` (single + batch) renvoie
`{playing, sessionId, game}` depuis les sessions en cours. Elle n'est affichée que sur le profil et dans le fil.
Équivalent Steam / Discord : la colonne d'amis avec ce qu'ils font.

## Critères d'acceptation

1. `GET /community/friends/now` renvoie les amis acceptés du viewer **en jeu**, avec jeu, type de partie
   (run perso / événement / hebdo), titre, et un lien vers la page publique ou de suivi si le viewer peut la
   voir ; puis les amis actifs récemment (dernière session terminée il y a moins de 24 h), sans lien.
2. Le réglage de visibilité de chaque ami (43.6) est respecté : un ami en mode discret n'apparaît pas.
3. Encart affiché sur `/compte` et sur `/communaute` pour un viewer connecté qui a au moins un ami ; s'il n'en a
   aucun, l'encart propose les suggestions de 43.2 ou renvoie vers l'annuaire.
4. Mise à jour : rafraîchissement au focus et toutes les 60 s (TanStack Query), pas de nouveau topic temps réel.
5. Le lien vers une partie n'est donné que si le viewer y a accès (run perso dont il est participant, recap
   public, événement public) ; sinon le jeu seul est affiché.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Application** : `FriendsNowQuery` qui combine amitiés + `CommunityPresenceQueryInterface` (batch) + accès.
      La présence ne couvre que les sessions **en cours** : « actif récemment » demande une lecture de plus
      (dernière session terminée par utilisateur), à ajouter à l'interface ou dans une requête dédiée.
- [x] **Présentation** : `GET /community/friends/now`.
- [x] **Front** : composant `FriendsNowCard` (avatars encadrés, pastille « En jeu »), état vide.
- [x] Tests fonctionnels (en jeu, récent, discret, accès au lien) et gates.

## Dev Notes (2026-10-09)

- `GET /api/v1/community/friends/now` (`CommunityFriendsNowController` -> `FriendsNowQuery`) renvoie
  `{hasFriends, playing, recent}`. `playing` : carte + jeu + `kind` (`event` | `run`) + `title`, `eventId`, `runId`
  donnés seulement si le viewer a accès (événement public, run dont il est propriétaire ou participant). `recent` :
  carte + jeu + `finishedAt` de la dernière session terminée depuis moins de 24 h, sans lien.
- Les deux listes passent par `CommunityPresenceQueryInterface` (nouvelle méthode `recentlyPlayed`), donc le
  réglage 43.6 et les blocages s'appliquent aussi aux « actifs récemment ». La lecture brute partage la même
  requête de base (`playerSlots`) que la présence en direct.
- Accès et titre : `FriendSessionContextQueryInterface` / `DbalFriendSessionContextQuery` (le `event_id` d'une session
  est un événement, sinon un id de run perso).
- **Écart** : pas de type « hebdo ». Les hebdos ne passent pas par `session_slot` (tables `weekly_entries`), la
  présence 30.14 ne les voit donc pas : un ami en hebdo n'apparaît pas en jeu. À traiter avec la présence riche (43.7)
  si on le veut.
- Front : `FriendsNowCard` sur `/compte` (aperçu) et `/communaute` (sous les chiffres), rafraîchi au focus et toutes
  les 60 s. Sans ami : lien vers l'annuaire et suggestions « Tu as joué avec » (43.2).
- Tests : `tests/Functional/FriendsNowTest.php`, `friends-now-card.test.tsx`.
