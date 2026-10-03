# Story 41.8: Indices contre pelles dans les hebdos

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant que joueur d'une hebdo,
je veux acheter un indice avec mes pelles en or quand l'hebdo le permet,
afin de profiter des pelles aussi en hebdo, comme en partie privée.

## Contexte

La 41.3 a branché l'achat d'indices en pelles sur les sessions (`/api/v1/sessions/...`) ; les hebdos passent par
leur propre API (`/api/v1/weekly-runs/{run}/entries/{entry}/slots/{n}/...`) et leur propre configuration (profil
« hebdo », surcharge par modèle d'hebdo), et n'avaient pas le bouton. Choix de Jean (2026-10-03) pour la suite de
l'epic. Une hebdo se joue seul : les primes (41.4) n'y ont pas de sens et restent hors périmètre.

## Critères d'acceptation

1. Endpoints `GET|POST /api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/pelle-hints`, ouverts au
   joueur de la tentative (ou à un admin), même contrat que ceux des sessions (41.3).
2. Le réglage est celui de la configuration des hebdos : profil « hebdo » et surcharge du modèle de l'hebdo
   (désactivé par défaut, prix d'objet et de lieu).
3. Paiement en pelles en or seulement (une hebdo n'est pas un événement). Refus si la tentative n'est pas lancée
   (409), si elle a déjà atteint son goal (409), si l'hebdo ne vend pas d'indices (403), si le solde ne suffit pas
   (422). Même débit, même indice gratuit vérifié auprès du bridge et même remboursement qu'en 41.3.
4. Fiche de slot d'une hebdo : le bouton « N pelles » dans la confirmation d'indice, comme sur une run privée.
5. Gates : `composer gates` et `pnpm gates` verts.

## Notes techniques

- `BuyHintWithPelles` expose sa vente (`sell`) une fois les conditions vérifiées ; la commande des hebdos vérifie
  les siennes (tentative, goal, configuration des hebdos) puis l'appelle. WeeklyRuns dépend de Sessions, jamais
  l'inverse.
- Le bridge d'une tentative est celui de son `externalSessionId`, déjà utilisé par les autres appels de la fiche.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - `BuyHintWithPelles::sell()`, `WeeklyPelleHintTerms`, `BuyWeeklyHintWithPelles`,
  `WeeklyPelleHintOfferQuery`, `WeeklyPelleHintController` ; tests fonctionnels.
- [x] **Task 2** (AC 4) - Hook `usePelleHintOffers(slotUrl)` commun aux sessions et aux hebdos, branché sur la fiche
  de slot d'une hebdo ; tests.
- [x] **Task 3** (AC 5) - Gates.

