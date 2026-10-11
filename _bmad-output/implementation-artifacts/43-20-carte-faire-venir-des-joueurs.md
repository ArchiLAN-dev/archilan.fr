# Story 43.20: Une seule carte pour faire venir des joueurs

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-11
**Dépend de:** 43.1, 43.14, 43.17

## Story

En tant que propriétaire d'une run perso,
je veux une seule carte claire pour choisir qui peut rejoindre ma partie et inviter du monde,
afin de comprendre d'un coup d'œil qui la voit et comment faire venir des joueurs.

## Contexte

Retour de Jean en testant la release v0.39.0 : en tête de la page d'une run, le lien d'invitation, le bouton
« Inviter des amis » et le réglage d'ouverture s'empilaient en trois blocs sans hiérarchie (petits boutons radio,
« Places pour tes amis » en champ nu, bouton « Enregistrer » toujours visible, gros bouton Discord en concurrence).

## Critères d'acceptation

1. Une carte « Faire venir des joueurs » regroupe, pour le propriétaire : l'action « Inviter des amis » (bouton
   principal), le réglage « Qui peut rejoindre ? » (brouillon seulement) et le lien d'invitation.
2. Les trois ouvertures sont des tuiles cliquables (icône, titre, phrase) au lieu de boutons radio ; la tuile choisie
   est mise en avant ; clavier et lecteur d'écran gardent un groupe de boutons radio.
3. Les places se règlent avec - et + autour du champ ; vide = « Illimité » ; - à 1 repasse en illimité.
4. « Enregistrer » n'apparaît que s'il y a quelque chose à enregistrer ; sinon un état dit où la partie est visible.
   Publier une annonce reste une action explicite.
5. Le lien d'invitation devient une ligne de la carte ; Discord passe en lien discret.
6. Comportement inchangé : mêmes appels API, « Sur invitation » et « Mes amis » s'appliquent tout de suite,
   l'annonce attend son message.
7. `pnpm gates` passe.

## Tasks / Subtasks

- [x] `RunRecruitPanel` (carte) dans la page de la run
- [x] `RunOpennessSetting` en tuiles, stepper de places, sauvegarde selon l'état
- [x] `InviteLinkPanel` en ligne de carte, Discord discret ; bouton « Inviter des amis » principal
- [x] Tests et gates

## Dev Notes

- Front seul, pas d'API ni de migration.
