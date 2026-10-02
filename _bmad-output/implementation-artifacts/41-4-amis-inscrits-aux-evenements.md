# Story 41.4: Amis inscrits aux événements

**Status:** draft
**Epic:** 41 - Des amis qui servent à jouer
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
4. Aucune notification par défaut quand un ami s'inscrit (c'est le rôle des favoris, 41.11).
5. Un blocage dans un sens ou l'autre exclut la personne.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Application** : `EventFriendsQueryInterface` (un événement + lot d'événements).
- [ ] **Infrastructure** : DBAL (inscriptions x amitiés acceptées, exclusion des blocages).
- [ ] **Présentation** : `GET /events/{eventId}/friends`, `GET /events/friends?ids=...`.
- [ ] **Front** : composant client `EventFriendsBadge` sur la page et les cartes.
- [ ] Tests fonctionnels (réservée, soumise, annulée, blocage, anonyme 401) et gates.

## Notes

- Les événements privés (`verify-private-access`) : ne renvoyer les amis que si le viewer a accès à l'événement.
