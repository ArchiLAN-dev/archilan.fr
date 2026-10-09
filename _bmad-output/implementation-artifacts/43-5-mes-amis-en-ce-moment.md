# Story 43.5: Encart « Mes amis en ce moment »

**Status:** draft
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

- [ ] **Application** : `FriendsNowQuery` qui combine amitiés + `CommunityPresenceQueryInterface` (batch) + accès.
      La présence ne couvre que les sessions **en cours** : « actif récemment » demande une lecture de plus
      (dernière session terminée par utilisateur), à ajouter à l'interface ou dans une requête dédiée.
- [ ] **Présentation** : `GET /community/friends/now`.
- [ ] **Front** : composant `FriendsNowCard` (avatars encadrés, pastille « En jeu »), état vide.
- [ ] Tests fonctionnels (en jeu, récent, discret, accès au lien) et gates.
