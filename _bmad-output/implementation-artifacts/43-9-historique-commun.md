# Story 43.9: Historique commun sur le profil d'un ami

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.2 (agrégat de co-participations)

## Story

En tant que joueur qui regarde le profil d'un ami,
je veux voir ce qu'on a joué ensemble,
afin de retrouver nos anciennes parties et d'avoir envie d'en relancer une.

## Contexte

L'agrégat de co-participations construit pour 43.2 (runs perso, événements) donne directement cette vue. Les
recaps (epic 32) donnent les items échangés par session (`SessionFeedEvent` : `sender_slot`, `receiver_slot`).

## Critères d'acceptation

1. Sur le profil d'un autre membre, pour un viewer connecté qui a au moins une partie commune avec lui, un bloc
   « Vous avez joué ensemble » affiche : nombre de parties, date de la première et de la dernière, et la liste
   des 5 dernières (titre, date, lien vers le recap si le viewer y a accès).
2. « Items échangés : *A* envoyés, *B* reçus » sur l'ensemble des parties communes ayant un feed persisté
   (sessions antérieures à l'epic 32 ignorées, mention « depuis *date* »).
3. Le bloc s'affiche aussi pour un non-ami (c'est un argument pour l'ajouter), mais jamais en cas de blocage.
4. Un bouton « Relancer une partie ensemble » ouvre la création de run perso avec cet ami présélectionné pour
   l'invitation 43.1, envoyée seulement quand la run est créée (si ami ; sinon le bouton propose d'abord la
   demande d'ami). Rien n'est créé sans action explicite.
5. Chargé côté client (SSR anonyme), une requête agrégée.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Application** : `SharedHistoryQueryInterface` (viewer, autre utilisateur).
- [x] **Infrastructure** : DBAL réutilisant l'agrégat de 43.2 + comptage des `SessionFeedEvent` par paire de slots.
- [x] **Présentation** : `GET /community/profiles/{slug}/shared-history`.
- [x] **Front** : bloc sur `player-profile-page.tsx`, bouton « Relancer ».
- [x] Tests fonctionnels et gates.

## Dev Notes (2026-10-09)

- `GET /api/v1/community/profiles/{slug}/shared-history` (`CommunitySharedHistoryController` -> `SharedHistoryQuery`)
  renvoie `null` quand rien n'a été joué ensemble, pour soi-même ou en cas de blocage ; sinon `userId`, `isFriend`,
  `count`, `firstAt`, `lastAt`, les 5 dernières (`kind`, `title`, `playedAt`, `recap` si le viewer peut ouvrir le
  recap via `ViewableRecapsQuery`) et `items {sent, received, since}`.
- `DbalSharedHistoryQuery` : mêmes « players » que 43.2 (propriétaires et co-joueurs), sessions de run perso ou
  d'événement. Items : le feed nomme les slots, chacun mène à ses joueurs (lien de `DbalItemsFromOthersQuery`) ;
  ce qu'un slot envoie après son release ou reçoit après son collect est exclu. `since` = premier événement de feed
  des sessions communes (null : aucun feed, la ligne n'est pas affichée).
- Visibilité (32.22) : le viewer a joué la partie, il la voit ; le lien vers le recap suit la règle d'accès du recap.
- Front : `SharedHistoryBlock` sous l'en-tête du profil. Ami : « Relancer une partie ensemble » ouvre `/runs?inviter=<id>`,
  le formulaire de création s'ouvre avec l'ami présélectionné, l'invitation 43.1 part après la création (« Ne pas
  inviter » le retire). Non-ami : « Ajouter en ami pour rejouer » envoie la demande d'ami. Rien n'est créé sans clic.
- `src/lib/use-location-param.ts` : lecture/écriture d'un paramètre d'URL sans `useSearchParams` (qui sort une page
  du prérendu) ; le classement de 43.8 l'utilise aussi désormais.
- Tests : `SharedHistoryTest`, `shared-history.test.tsx`.
