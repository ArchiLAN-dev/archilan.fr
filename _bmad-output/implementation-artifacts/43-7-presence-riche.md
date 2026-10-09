# Story 43.7: Présence riche - où en est l'ami dans sa partie

**Status:** draft
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.6

## Story

En tant que joueur,
je veux voir où en est un ami dans sa partie (progression, BK, objectif atteint),
afin de savoir s'il a besoin d'aide ou s'il vient de finir.

## Contexte

Équivalent de la *rich presence* Steam. Les données existent : `SessionPlayersSnapshot` (payload `players` du
bridge : `checks_done`, `checks_total`, `reachable_now`, `client_status`), règle BK `SlotBlockRule` (story 40.1).
Le snapshot est indexé par slot, pas par utilisateur : il faut passer de l'utilisateur à son ou ses slots. En run
perso `SessionSlot::registrationId` est l'id utilisateur ; en événement c'est l'id de l'inscription
(`Registration.userId`) ; les co-joueurs passent par `SlotCoPlayer`.

## Critères d'acceptation

1. La présence d'un utilisateur en jeu s'enrichit d'un état de slot : progression `checks_done / checks_total`,
   `bk` (via `SlotBlockRule`, même règle que le badge), `goal` (objectif atteint), ou inconnu.
2. Affichage : « En jeu · Hollow Knight · 42 % », « En BK », « Objectif atteint » dans 43.5, sur le profil et
   dans le fil.
3. Parties avec plusieurs slots pour un même utilisateur : on affiche le slot le moins avancé non terminé
   (règle documentée et testée).
4. Seed importée sans suivi détaillé : seul le jeu est affiché.
5. Le réglage de 43.6 s'applique ; pas de détail de progression pour un viewer qui ne voit pas la présence.
6. Une seule lecture de snapshot par session (batch), pas de N+1.
7. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Application** : extension du DTO de présence (`slotState`, `progressPercent`), lecture batch des snapshots.
- [ ] **Infrastructure** : résolution utilisateur vers slots pour les trois cas (run perso, événement, co-joueur),
      en une requête.
- [ ] **Front** : libellés et pastilles (réutiliser les couleurs BK / goal de `PlayerProgressGrid`).
- [ ] Tests (règle multi-slot, BK, goal, inconnu, seed importée) et gates.
