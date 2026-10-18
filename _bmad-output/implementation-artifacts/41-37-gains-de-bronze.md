# Story 41.37: Pelles de bronze - gains en partie

**Status:** review
**Epic:** 41 - Pelles, la monnaie du site
**Date:** 2026-10-18
**Dépend de :** 41.36 (type, réglages, cycle de vie).

## Story

En tant que joueur d'une partie privée,
je veux recevoir du bronze au lancement, à chacun de mes checks et à mon goal,
afin d'avoir de quoi payer hints et primes dans cette partie.

## Contexte

Les trois sources sont réglées par partie (41.36, AC 3). Ce que le site sait de la partie en cours :

- **Les checks** n'arrivent que par le fil de session (`FeedPushController`, type `item-received`, avec
  `sender.slot` = le slot qui a vérifié la location et `location.id`). Il n'y a pas de compteur de checks en
  direct en base : `session_slot.checks_done` n'est rempli qu'au goal (`RecordSlotGoal`). Le fil est
  best-effort : un événement perdu par le bridge est un gain perdu, comme pour les primes (41.4).
- **Le goal** arrive par le rappel du bridge (`SlotGoalCallbackController` puis `RecordSlotGoal`), fiable et
  idempotent (`SessionSlot::recordGoal`).
- **`!release` et `!collect`** produisent aussi des `item-received` : un release envoie tout ce qui reste du
  slot qui libère, un collect fait « trouver » par les autres slots les items du slot qui collecte. Ce ne sont
  pas des checks joués. La 41.4 a déjà la règle pour ne pas les payer (`was_released`, slot déjà au goal).
- **Co-joueurs** : un co-joueur peut être ajouté à un slot à tout moment, y compris après le lancement
  (`RunSlotCoPlayers`, aucune restriction de statut).

Décision de Jean à confirmer à la relecture : qui est crédité pour un check ou un goal d'un slot joué à
plusieurs. Cette story propose **chaque joueur du slot (propriétaire et co-joueurs), chacun sur son solde**,
parce que le solde est par joueur et qu'un co-joueur doit pouvoir payer un hint pour le slot qu'il joue. Cela
diffère de la règle des primes (décision 7 : la prime va au propriétaire seul), qui reste inchangée.

## Critères d'acceptation

1. **Dotation de départ.** Au lancement de la partie (passage à `active` la première fois), chaque joueur de la
   partie (propriétaire de slot ou co-joueur) reçoit la dotation (`bronze_start`, clé
   `bronze-start:{run}:{membre}`). Un co-joueur ajouté après le lancement la reçoit à son ajout. Une pause, une
   reprise ou un redémarrage ne redonne rien (la clé l'empêche). Un joueur sans slot ni co-jeu ne reçoit rien.
2. **Par check.** Un `item-received` dont l'expéditeur est un slot de la partie crédite chaque joueur de ce slot
   du montant « par check » (`bronze_check`, clé `bronze-check:{run}:{slot}:{location}:{membre}`), une seule fois
   par location même si l'événement est reçu deux fois (relance, rejeu).
3. **Pas de gain sans check joué**, comme la 41.4 AC 5 : rien si l'expéditeur ou le destinataire a fait
   `!release` / `!collect`, ou si l'expéditeur avait déjà atteint son goal au moment de l'envoi ; rien pour un
   expéditeur sans joueur sur le site (slot observateur, joueur inconnu). Un item trouvé pour soi-même (expéditeur
   = destinataire) est un check joué : il paie.
4. **Au goal.** Le goal d'un slot crédite chacun de ses joueurs du montant « au goal » (`bronze_goal`, clé
   `bronze-goal:{run}:{slot}:{membre}`), une fois.
5. **Montant à 0** : la source est coupée, aucun mouvement n'est écrit (pas de ligne à 0).
6. **Best-effort.** Un crédit en échec est loggé et ne casse ni le fil, ni la publication Mercure, ni
   l'enregistrement du goal (même place et même garde que `SettleItemBounties` dans `FeedPushController`).
7. **Compte banni** : rien n'est crédité (règle de `RecordPelleMovement`).
8. **Volume.** Un crédit par check et par joueur fait beaucoup de lignes sur une grosse partie : l'historique de
   bronze (41.38) les regroupe ; le registre garde une ligne par check pour l'anti-doublon.
9. **Tests** : fonctionnels (dotation au lancement, à l'ajout d'un co-joueur, pas à la reprise ; check crédité
   une fois, au propriétaire et au co-joueur ; release, collect, goal déjà atteint et observateur ne paient pas ;
   goal crédité une fois ; montant à 0 sans ligne ; partie hebdo ou event jamais créditée en bronze).
10. `composer gates` passe.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Dotation au premier passage à `active` (handler de lancement) et à l'ajout d'un
  co-joueur sur une partie lancée.
- [x] **Task 2** (AC 2, 3, 5-7) - Service de crédit appelé par `FeedPushController` après les primes ;
  résolution des joueurs d'un slot (propriétaire + co-joueurs) ; règles d'exclusion partagées avec
  `SettleItemBounties` plutôt que recopiées.
- [x] **Task 3** (AC 4) - Crédit au goal depuis `RecordSlotGoal`, pour une partie privée seulement.
- [x] **Task 4** (AC 9, 10) - Tests et gates.

## Dev Notes

- Les règles « pas un check joué » existent déjà dans `SettleItemBounties` : les extraire dans un service commun
  pour que primes et gains ne divergent jamais.
- Vérifier sur une vraie partie que le bridge annonce bien chaque check par un `item_sent`, y compris les items
  trouvés pour soi-même.

## Dev Agent Record

- **Dotation** : dans `ReconcileRunBronze` (41.36), branche « partie jouable » : après la restitution, chaque joueur
  de la session (propriétaires de slot et co-joueurs, `RunSlotPlayers`) reçoit le départ, clé
  `bronze-start:{run}:{membre}`. Le job est envoyé quand la session d'une partie privée passe `running`
  (`SessionLifecycleManager::transition`) et quand les co-joueurs d'un slot changent (`RunSlotCoPlayers`) ; une
  relance repasse par là sans rien redonner. Un joueur banni ne reçoit rien (pas `byAdmin`).
- **Checks et goal** : `CreditRunBronze` (Sessions), appelé par `FeedPushController` après les primes et par
  `RecordSlotGoal` après l'enregistrement du goal, chacun dans son `try/catch` (best-effort). Clés
  `bronze-check:{run}:{ordre du slot}:{location}:{membre}` et `bronze-goal:{run}:{ordre du slot}:{membre}`.
- **Règle commune** : `SessionSlot::stoppedFinding($at)` remplace le `releasedOrDone` privé de
  `SettleItemBounties` ; primes et bronze ne peuvent plus diverger.
- Une partie terminée ou annulée ne donne pas de départ : la branche « terminée » du réconciliateur brûle et
  s'arrête.

### Tests

- `RunBronzeEarningsTest` : départ une fois à tous (propriétaires et co-joueur), même relancé ; co-joueur ajouté
  plus tard ; check payé une fois par location, au propriétaire et au co-joueur, item pour soi-même compris,
  recevoir n'est pas checker ; release, collect, goal déjà atteint, slot sans joueur ne paient pas ; goal payé une
  fois ; montants du propriétaire et 0 sans ligne ; banni sans gain, les autres payés ; partie terminée sans
  départ.
- `ItemBountyTest` inchangé et vert (règle extraite).
- Non couvert par un test : l'envoi du job au passage `running` (une ligne, même place que `markRunning`).
