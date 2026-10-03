# Story 41.9: Fiche de slot des joueurs d'événement

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant que joueur d'un événement,
je veux ouvrir la fiche de mon slot (checks, items, indices), comme dans une partie privée,
afin de suivre ma progression et de dépenser mes pelles d'événement en indices.

## Contexte

La fiche de slot existe pour les runs privées (`/runs/{run}/progression/{slot}`) et pour l'admin ; un joueur
d'événement ne voyait que sa page de connexion, avec des cartes de slots non cliquables. L'API l'autorise déjà sur
les endpoints de session (`isUserAuthorizedForSession`, `doesUserOwnSlot` reconnaissent l'inscription). Sans cette
fiche, les pelles d'événement ne pouvaient pas s'y dépenser en indices (41.3). Choix de Jean pour la suite de l'epic.

## Critères d'acceptation

1. Route `/evenements/{slug}/inscription/{registrationId}/session/slots/{slotIndex}` : la fiche de slot de la run
   privée (onglets, temps réel, indices en points et en pelles, primes), alimentée par la session de l'inscription
   (`/registrations/{id}/session-connection`). Pas de bascule des spoilers pour un joueur (un admin la garde).
2. Le sélecteur de slots ne propose que les slots du joueur ; le lien de retour mène à sa page de session.
3. Sur la page de session, les cartes de progression des slots du joueur ouvrent leur fiche ; celles des autres
   joueurs restent des cartes.
4. Les runs privées gardent exactement leur fiche (mêmes routes et liens).
5. Gates : `pnpm gates` vert (aucun changement d'API).

## Dev Agent Record

- `PersonalRunSlotDetailPage` devient un enveloppe d'un `SlotDetailPage` paramétré par sa source (`SlotSource` : run
  ou inscription d'événement) ; `EventSlotDetailPage` est l'autre enveloppe. Les seules différences : la session
  (run ou `fetchSessionConnection`), le lien de retour, et le sélecteur limité aux slots du joueur.
- `PlayerProgressGrid` accepte `slotHref(slotIndex, slotName)` ; la page de session ne lie que les slots du joueur.
