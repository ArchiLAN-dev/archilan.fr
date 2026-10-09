# Story 43.10: Les amis dans le recap

**Status:** draft
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que joueur qui consulte le recap d'une partie,
je veux voir ce que mes amis et moi nous sommes envoyé,
afin de revivre les moments où l'un a débloqué l'autre.

## Contexte

Spécificité du multiworld, qu'aucune plateforme généraliste n'a. Le feed persisté du recap (epic 32,
`SessionFeedEvent`) porte `item_name`, `item_flags`, `sender_slot/name`, `receiver_slot/name`. Les déblocages de
BK sont connus depuis 40.1 (`SlotBlockEpisode`).

## Critères d'acceptation

1. Dans le recap d'une session, pour un viewer qui y a joué, un bloc « Entre nous » liste ses échanges avec
   chaque autre joueur ami ou co-joueur : items envoyés / reçus, dont items de progression (`item_flags`).
2. Moment marquant (runs perso seulement, seules suivies par 40.1) : « *X* t'a envoyé *Item* qui t'a sorti du
   BK » quand un item de progression reçu précède la clôture d'un épisode de BK du viewer (fenêtre courte,
   constante documentée).
3. Les amis sont mis en avant (en premier, avec avatar) ; les non-amis co-joueurs suivent, avec un bouton
   « Ajouter » (raccourci vers 43.2).
4. Calcul côté serveur, une requête agrégée par recap, mis en cache avec le recap si possible.
5. Visible seulement des participants de la session (pas sur un recap public vu par un tiers).
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Application** : `RecapExchangesQueryInterface` (session, viewer).
- [ ] **Domaine/Migration** : historique des épisodes de BK clos par un déblocage (session, slot, début, fin).
      `SlotBlockTracker` **supprime** aujourd'hui l'épisode à la clôture (`$this->episodes->remove()`) : il faut
      l'archiver au même moment, dans la même transaction.
- [ ] **Infrastructure** : DBAL sur `session_feed_event` + historique des épisodes.
- [ ] **Présentation** : `GET /sessions/{sessionId}/recap/exchanges`.
- [ ] **Front** : bloc dans la page recap (`features/recap`).
- [ ] Tests et gates.

## Notes

- L'historique des BK ne commence qu'au déploiement : pas de « sorti du BK » sur les parties plus anciennes.
- Les items envoyés par un slot que plusieurs personnes jouent (co-joueurs) sont attribués au slot, pas à une
  personne : afficher « *X* et *Y* ».
