# Story 41.9: Historique commun sur le profil d'un ami

**Status:** draft
**Epic:** 41 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 41.2 (agrégat de co-participations)

## Story

En tant que joueur qui regarde le profil d'un ami,
je veux voir ce qu'on a joué ensemble,
afin de retrouver nos anciennes parties et d'avoir envie d'en relancer une.

## Contexte

L'agrégat de co-participations construit pour 41.2 (runs perso, événements) donne directement cette vue. Les
recaps (epic 32) donnent les items échangés par session (`SessionFeedEvent` : `sender_slot`, `receiver_slot`).

## Critères d'acceptation

1. Sur le profil d'un autre membre, pour un viewer connecté qui a au moins une partie commune avec lui, un bloc
   « Vous avez joué ensemble » affiche : nombre de parties, date de la première et de la dernière, et la liste
   des 5 dernières (titre, date, lien vers le recap si le viewer y a accès).
2. « Items échangés : *A* envoyés, *B* reçus » sur l'ensemble des parties communes ayant un feed persisté
   (sessions antérieures à l'epic 32 ignorées, mention « depuis *date* »).
3. Le bloc s'affiche aussi pour un non-ami (c'est un argument pour l'ajouter), mais jamais en cas de blocage.
4. Un bouton « Relancer une partie ensemble » ouvre la création de run perso avec cet ami présélectionné pour
   l'invitation 41.1, envoyée seulement quand la run est créée (si ami ; sinon le bouton propose d'abord la
   demande d'ami). Rien n'est créé sans action explicite.
5. Chargé côté client (SSR anonyme), une requête agrégée.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Application** : `SharedHistoryQueryInterface` (viewer, autre utilisateur).
- [ ] **Infrastructure** : DBAL réutilisant l'agrégat de 41.2 + comptage des `SessionFeedEvent` par paire de slots.
- [ ] **Présentation** : `GET /community/profiles/{slug}/shared-history`.
- [ ] **Front** : bloc sur `player-profile-page.tsx`, bouton « Relancer ».
- [ ] Tests fonctionnels et gates.
