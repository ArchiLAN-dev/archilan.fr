# Story 32.23: Superlatifs à partir de deux joueurs, parties perso comprises

**Status:** review
**Epic:** 32 - Récap de partie
**Date:** 2026-10-11

## Story

En tant que membre,
je veux qu'un superlatif (et le succès qui en découle) récompense une partie jouée à plusieurs, événement ou partie
perso,
afin qu'un joueur seul ne gagne pas « Speedy Gonzales » et « Le Seigneur des Anneaux » d'office, et que mes parties
entre amis comptent aussi.

## Contexte

Demande de Jean (2026-10-11) sur les succès de superlatifs (story 32.4) : minimum deux joueurs, et ça doit marcher en
partie perso.

- `RecapSuperlativesCalculator` (Sessions) ne vérifie aucun nombre de joueurs.
- « Le Parrain » (`most_generous`) et « Le Facteur » (`biggest_hub`) demandent déjà un envoi vers un autre slot (les
  objets gardés pour soi sont comptés à part dans `localItemCounts`).
- « Speedy Gonzales » (`first_to_goal`) et « Le Seigneur des Anneaux » (`longest_road`) ne regardent que les heures
  d'atteinte du but : un joueur seul qui finit gagne les deux d'un coup.
- `DbalRecapSuperlativesQuery` (Community) ne lit que les sessions terminées d'un **événement public**, via
  `registration`. Les parties perso ont bien un récap avec ses superlatifs (32.5), mais il ne rapporte aucun succès.
- En partie perso, un slot n'est pas un joueur : `session_slot.registration_id` porte directement l'id du membre
  propriétaire, un membre peut posséder plusieurs slots, et un slot peut avoir des co-joueurs (`slot_co_player`).
  `DbalSlotPlayerSource` (story 16.17) est l'unique endroit qui transforme un slot en ses joueurs (propriétaire et
  co-joueurs, événement comme partie perso).
- Les parties hebdo n'ont pas de `session_slot` par joueur : hors sujet.
- Les succès sont monotones (`RecomputeAchievements` n'en retire jamais) : un succès déjà gagné en solo reste acquis.
  Seul un admin peut le retirer à la main (story 30.34).

### Décisions

1. **Joueurs = membres distincts** de la session : propriétaires des slots et co-joueurs, sans doublon. Une partie
   perso avec un seul membre sur trois slots compte pour un joueur.
2. **La règle vit dans le calcul du récap** : le récap affiche les mêmes superlatifs que ceux qui comptent pour les
   succès. `BuildSessionRecapJobHandler` compte les joueurs distincts (il résout déjà les propriétaires dans
   `resolveParticipantUserIds`, à étendre aux co-joueurs) et le passe au calculateur, qui reste pur :
   `calculate(RecapGraph $graph, array $goalReachedBySlotName, int $playerCount)`.
3. **Succès en partie perso** : `DbalRecapSuperlativesQuery` lit aussi les sessions terminées des parties perso, et
   attribue un superlatif à tous les joueurs du slot gagnant (propriétaire et co-joueurs) via `DbalSlotPlayerSource`,
   comme les autres agrégats depuis 16.17. Les événements gardent leurs règles (événement public, inscription
   soumise, statut `reserved`) ; les co-joueurs y sont aussi crédités, par cohérence.

## Critères d'acceptation

1. Une session avec moins de deux joueurs distincts n'a aucun superlatif : ni dans le récap, ni pour les succès.
2. À partir de deux joueurs, rien ne change dans le calcul, y compris quand un seul atteint son but.
3. Une partie perso terminée à au moins deux joueurs compte pour les succès de superlatifs (fait `superlatives` et
   `superlative:{key}`), pour le propriétaire du slot gagnant et ses co-joueurs. Un superlatif n'est compté qu'une
   fois par membre et par session, même s'il joue plusieurs slots.
4. Les sessions d'événement continuent de compter comme avant (public, inscription soumise) ; un événement privé ou
   une session non terminée ne compte toujours pas.
5. Le récap construit à la fin d'une partie perso déclenche le recalcul des succès de tous ses joueurs, co-joueurs
   compris.
6. Le front gère un récap sans superlatifs (récap public, récap de partie perso, carte de partage) : à vérifier, sans
   changement attendu.
7. Les récaps existants ne sont pas réécrits automatiquement. La note de livraison donne :
   - `app:sessions:rebuild-recap <sessionId>...` pour les sessions solo à corriger ;
   - le recalcul global des succès, pour accorder les superlatifs déjà gagnés en partie perso ;
   - le rappel que les succès déjà accordés en solo restent (retrait manuel par l'admin si voulu).
8. Gates verts ; tests :
   - unitaires (`RecapSuperlativesCalculatorTest`) : un joueur, aucun superlatif ; deux joueurs dont un seul finit,
     premier et dernier au but présents ;
   - fonctionnels : un joueur seul sur plusieurs slots d'une partie perso n'a aucun superlatif ; une partie perso à
     deux crédite le gagnant et son co-joueur ; un membre sur deux slots gagnants ne compte qu'une fois ; une
     session d'événement privé ne compte pas.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - `RecapSuperlativesCalculator::calculate()` prend `$playerCount` et renvoie `[]` sous deux ;
  docblock ; tests unitaires.
- [x] **Task 2** (AC 1, 5) - `BuildSessionRecapJobHandler` : joueurs distincts (propriétaires et co-joueurs), passés
  au calculateur et au déclencheur de recalcul ; tests.
- [x] **Task 3** (AC 3, 4) - `DbalRecapSuperlativesQuery` : sessions de partie perso terminées, joueurs via
  `DbalSlotPlayerSource`, un compte par (membre, session, superlatif) ; docblock ; tests fonctionnels.
- [x] **Task 4** (AC 6) - Vérifier le rendu d'un récap sans superlatifs.
- [x] **Task 5** (AC 7, 8) - Gates ; préparer la note de livraison (sessions solo concernées en prod, commandes).

## Dev Agent Record

- `RecapSuperlativesCalculator` : `MIN_PLAYERS = 2`, `calculate()` prend `$playerCount` et renvoie `[]` en dessous.
- `BuildSessionRecapJobHandler` : `resolveParticipantUserIds` ajoute les co-joueurs (`SlotCoPlayerRepositoryInterface`) ;
  le compte des membres distincts part au calculateur, la même liste au recalcul des succès.
- `DbalRecapSuperlativesQuery` : sessions terminées d'un événement public (inscription soumise) ou d'une partie perso
  (`run.id = session.event_id`) ; joueurs via `DbalSlotPlayerSource` ; une session de moins de deux membres distincts
  est écartée **aussi dans la requête**, parce que les récaps construits avant cette story portent encore leurs
  superlatifs solo (sans ça, le recalcul les aurait accordés en partie perso). Un compte par (session, clé).
- Front : rien à changer. Les superlatifs ne sont que des badges dans le tableau des joueurs (`build-player-rows.ts`)
  et la carte de partage renvoie déjà `null` sans superlatif.
- Livraison : le recalcul horaire planifié (`RecomputeAllAchievementsMessage`, sans notification) accorde dans l'heure
  les superlatifs déjà gagnés en partie perso ; `community:achievements:recompute` pour le faire tout de suite.
  `app:sessions:rebuild-recap` sur une session solo ne sert plus qu'à retirer les badges de son récap.
- Gates API verts (2958 tests).
