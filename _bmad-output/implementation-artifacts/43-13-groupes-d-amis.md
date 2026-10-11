# Story 43.13: Groupes d'amis

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.1

## Story

En tant que joueur qui monte souvent des parties avec les mêmes personnes,
je veux regrouper mes amis en groupes nommés (« La team du jeudi »),
afin d'inviter tout le groupe en un clic.

## Contexte

Équivalent des catégories d'amis Steam, en plus léger que les clubs Strava : un groupe est **privé à son
créateur** (une étiquette personnelle), pas une entité communautaire partagée.

## Critères d'acceptation

1. Depuis `/compte/amis`, créer, renommer, supprimer un groupe ; y ajouter ou retirer des amis acceptés. Un ami
   peut être dans plusieurs groupes. Maximum 20 groupes, 30 membres par groupe.
2. Les groupes ne sont visibles que de leur créateur ; les membres ne savent pas qu'ils y sont.
3. Dans la modale d'invitation de 43.1, un groupe se coche d'un coup (les membres déjà participants ou bloqués
   sont ignorés, avec un récapitulatif).
4. Retirer une amitié ou bloquer retire la personne de tous les groupes du créateur.
5. Filtre par groupe dans l'annuaire filtré sur les amis.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Domaine/Migration** : `FriendGroup` (owner, nom) et membres ; invariants (plafonds, membres = amis).
- [x] **Application** : commandes CRUD ; nettoyage sur retrait d'amitié / blocage (handler post-commit).
- [x] **Présentation** : routes `/community/friend-groups`.
- [x] **Front** : gestion dans `/compte/amis`, sélection dans la modale 43.1, filtre annuaire.
- [x] Tests et gates.

## Notes

- Évolution possible : groupe **partagé** (tous les membres le voient, fil et classement de groupe, à la Strava
  clubs). Hors périmètre ici, à reconsidérer selon l'usage.

## Dev Notes

- **Stockage** : `community_friend_group` (propriétaire, nom de 1 à 60 caractères, espaces resserrés) et
  `community_friend_group_member` (groupe, propriétaire répété, membre ; unique par groupe), migration
  `Version20261010130000`. Plafonds dans `FriendGroup` : 20 groupes, 30 membres par groupe.
- **Application** : `FriendGroupService` (façade lecture + écriture, comme `FriendshipService`). Seul un ami accepté
  entre dans un groupe ; le groupe d'un autre membre répond « introuvable » (404), ce qui garde les groupes privés.
  Chaque écriture renvoie les groupes tels qu'ils sont maintenant.
- **Nettoyage** : `FriendshipService::removeFriendship` et `block` appellent `FriendGroupRepositoryInterface::removeBetween`,
  dans les deux sens, comme les favoris de la 43.11a. Écart avec la tâche : pas de handler post-commit, c'est la
  même unité de travail que le retrait de l'amitié. Les groupes restent, vidés de la personne.
- **Routes** : `GET|POST /api/v1/community/friend-groups`, `PATCH|DELETE /api/v1/community/friend-groups/{groupId}`,
  `PUT|DELETE /api/v1/community/friend-groups/{groupId}/members/{userId}`. Erreurs 422 : `invalid_name`,
  `group_limit`, `member_limit`, `not_friend`.
- **Annuaire** : paramètre `group` pris en compte seulement avec `friendsOnly` ; un groupe qui n'est pas au membre
  donne une liste vide.
- **Front** :
  - carte « Mes groupes » dans `/compte/amis` (`friend-groups-panel.tsx`) : créer, renommer, supprimer (avec
    confirmation), ajouter par une liste déroulante, retirer d'un clic ;
  - modale d'invitation 43.1 : une puce par groupe coche ses membres invitables (`groupPick`), avec un récapitulatif
    des ignorés (déjà dans la partie ou invités, ou plus amis ; un bloqué n'est plus ami) ;
  - annuaire : liste « Groupe » quand « Mes amis uniquement » est actif.
- **Tests** : `FriendGroupsTest` (fonctionnel), `friend-groups.test.tsx`.
