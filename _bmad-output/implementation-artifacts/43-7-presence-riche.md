# Story 43.7: Présence riche - où en est l'ami dans sa partie

**Status:** review
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

- [x] **Application** : extension du DTO de présence (`slotState`, `progressPercent`), lecture batch des snapshots.
- [x] **Infrastructure** : résolution utilisateur vers slots pour les trois cas (run perso, événement, co-joueur),
      en une requête.
- [x] **Front** : libellés et pastilles (réutiliser les couleurs BK / goal de `PlayerProgressGrid`).
- [x] Tests (règle multi-slot, BK, goal, inconnu, seed importée) et gates.

## Dev Notes (2026-10-09)

- **Choix validés par Jean (2026-10-09)** :
  - Plusieurs slots : on garde la règle de 30.45 (ses slots avant les co-joués, puis le plus récemment checké), et non
    « le moins avancé » de l'AC 3, pour que le jeu affiché ne change pas d'une surface à l'autre. S'y ajoute en tête :
    un slot encore joué passe avant un slot dont l'objectif est atteint (`DbalCommunityPresenceQuery::shownBefore`).
  - Objectif : un slot dont le goal a été atteint dans la dernière demi-heure garde le membre « en jeu », état
    « Objectif atteint » ; passé ce délai il sort de la présence comme avant.
- `PresenceSlotState` (Community Domain/Enum) : `of(snapshotSlot, goalReached, tracked)` donne l'état et le
  pourcentage. Le BK est `SlotBlockRule::stateOf`, la règle du badge. Tous les checks faits sans goal = « en jeu » à
  100 %. Sans snapshot, ou seed importée (`run.imported_output_key`), état `unknown` : le jeu seul.
- La lecture brute donne le nom du slot, le goal et le suivi ; `snapshotSlots` lit les snapshots de toutes les sessions
  affichées en une requête, et seulement pour les présences que le viewer peut voir (AC 5 et 6).
- `CommunityPresenceQueryInterface::playing` renvoie `slotState` et `progressPercent` ; repris par le profil
  (`presence`), le fil (`actor`) et 43.5 (`playing`). Le hub (`playingNow`) n'en a pas besoin.
- Front : `rich-presence.ts` (libellés, couleurs BK/goal de `PlayerProgressGrid`) utilisé par le badge du profil, le
  point du fil (libellé au survol) et l'encart 43.5.
- Tests : `RichPresenceTest`, `PresenceSlotStateTest`, `CommunityPresenceLastCheckTest` (goal hors fenêtre),
  `rich-presence.test.ts`, `friends-now-card.test.tsx`.
