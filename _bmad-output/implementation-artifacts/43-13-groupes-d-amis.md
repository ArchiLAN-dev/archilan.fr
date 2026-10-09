# Story 43.13: Groupes d'amis

**Status:** draft
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

- [ ] **Domaine/Migration** : `FriendGroup` (owner, nom) et membres ; invariants (plafonds, membres = amis).
- [ ] **Application** : commandes CRUD ; nettoyage sur retrait d'amitié / blocage (handler post-commit).
- [ ] **Présentation** : routes `/community/friend-groups`.
- [ ] **Front** : gestion dans `/compte/amis`, sélection dans la modale 43.1, filtre annuaire.
- [ ] Tests et gates.

## Notes

- Évolution possible : groupe **partagé** (tous les membres le voient, fil et classement de groupe, à la Strava
  clubs). Hors périmètre ici, à reconsidérer selon l'usage.
