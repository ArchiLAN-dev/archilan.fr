# Story 30.50: Le Discord mis en avant

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-07

## Story

En tant que visiteur ou membre,
je veux voir que la communauté ArchiLAN vit sur Discord, et combien de monde s'y trouve,
afin de la rejoindre au moment où elle me sert.

## Contexte

Le Discord n'apparaissait qu'en bas de l'accueil (une carte avec une icône générique), dans le pied de page et sur la
page Communauté. Maquette validée par Jean le 2026-10-07 (canvas « Discord mis en avant »), à voir codée sur la
branche avant le merge.

## Critères d'acceptation

1. **En-tête** : un bouton Discord avec le logo et le nombre de membres en ligne, sur toutes les pages publiques ;
   l'icône seule sur téléphone ; un encart « La communauté est sur Discord » en bas du menu mobile.
2. **Accueil** : « Rejoindre le Discord » à côté de « Voir les événements », la ligne « N membres sur le Discord · M
   en ligne en ce moment » sous les boutons, et une section « La communauté vit sur Discord » avec la carte du serveur
   (nom, chiffres, ce qu'on y fait, « Rejoindre le serveur ») à la place de l'ancienne petite carte.
3. **Aux bons moments** : « Une question sur l'événement ? » sur la page d'un événement à venir ; « Prochaine étape :
   rejoindre le Discord » après la confirmation d'une inscription ; « Trouver des co-joueurs » sous le lien
   d'invitation d'une partie.
4. **Chiffres** : lus côté serveur depuis l'API publique des invitations Discord (aucun bot), gardés cinq minutes ;
   Discord injoignable : aucun chiffre affiché, les boutons restent.
5. Gates verts ; tests.

## Tasks / Subtasks

- [x] **Task 1** (AC 4) - `features/discord/discord-api.ts` (`inviteCode`, `isDiscordInvite`, `fetchDiscordStats`).
- [x] **Task 2** (AC 1-3) - `features/discord/discord-promo.tsx` (bouton d'en-tête, icône, bouton, ligne de
  chiffres, carte du serveur, encart) ; branchement en-tête, menu mobile, accueil, événement, confirmation, lien
  d'invitation.
- [x] **Task 3** (AC 5) - `discord.test.tsx` ; gates.

## Dev Agent Record

- Les chiffres de l'en-tête sont lus par le layout public (serveur) et passés à `PublicShell` ; l'accueil et la page
  événement relisent la même requête (cache `fetch` partagé, revalidation 300 s).
- Jeton `--color-discord-light` (#9AA3FF) pour le logo sur fond sombre, à côté de `--color-discord` existant.
- La confirmation d'inscription et le lien d'invitation sont des composants client : l'encart y est sans chiffres.
