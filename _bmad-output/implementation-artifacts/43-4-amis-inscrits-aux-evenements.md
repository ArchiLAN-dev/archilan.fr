# Story 43.4: Amis inscrits aux événements

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que membre qui hésite à s'inscrire à un événement,
je veux voir lesquels de mes amis y sont déjà inscrits,
afin de savoir avec qui je jouerai et d'avoir envie de venir.

## Contexte

Les inscriptions vivent dans `Registrations` (`POST /events/{eventId}/registrations`,
`GET /account/registrations`). Le modèle n'a que deux statuts, `reserved` et `cancelled` ; la soumission /
confirmation pose `submittedAt`. Le SSR du front est toujours anonyme (cookie host-bound) : l'information
personnalisée se charge côté client.

## Critères d'acceptation

1. `GET /events/{eventId}/friends` (authentifié) renvoie les amis acceptés du viewer ayant une inscription
   `reserved` à l'événement (les `cancelled` sont exclues), avec avatar et pseudo. Une inscription réservée mais
   pas encore soumise compte : l'ami a déclaré son intention de venir.
2. La page d'un événement affiche « *N* de tes amis participent » avec les avatars (3 visibles + compteur),
   chargé côté client ; rien pour un anonyme ou sans ami inscrit.
3. Les cartes de la liste des événements à venir affichent la même pastille, via une requête groupée.
4. Aucune notification par défaut quand un ami s'inscrit (c'est le rôle des favoris, 43.11).
5. Un blocage dans un sens ou l'autre exclut la personne.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Application** : `EventFriendsQueryInterface` (un lot d'événements) et `EventFriendsQuery` (un ou plusieurs).
- [x] **Infrastructure** : DBAL (inscriptions x amitiés acceptées, exclusion des blocages).
- [x] **Présentation** : `GET /events/{eventId}/friends`, `GET /community/event-friends?ids=...` (voir Dev Notes).
- [x] **Front** : composant client `EventFriendsBadge` sur la page et les cartes.
- [x] Tests fonctionnels (réservée, soumise, annulée, blocage, anonyme 401) et gates.

## Notes

- Les événements privés (`verify-private-access`) : ne renvoyer les amis que si le viewer a accès à l'événement.

## Dev Notes

- **Route groupée** : `GET /community/event-friends?ids=a,b` au lieu de `/events/friends` : `GET /events/{eventId}`
  existe déjà et lirait « friends » comme un identifiant d'événement. 50 événements au plus par appel ; la
  réponse est un objet `{eventId: cartes}` dont les événements sans ami sont absents.
- **Accès** : seuls les événements publiés comptent, publics ou ouverts au viewer (accès privé accordé dans
  `event_private_access_log`, ou viewer lui-même inscrit). Sinon la liste est vide, sans 404, pour ne rien
  révéler de l'existence de l'événement.
- **Amis** : amitié `accepted` uniquement (une demande en attente ne compte pas), inscription `reserved`
  soumise ou non, blocage dans un sens ou l'autre exclu, membre non listable écarté par `cards()`.
- **Front** : sur la page d'un événement non terminé, sous la description ; sur les cartes des événements à
  venir, une seule requête partagée par toutes les cartes (même clé TanStack). Trois avatars, `+N`, et la
  liste des noms au survol. Aucune notification (43.11).
