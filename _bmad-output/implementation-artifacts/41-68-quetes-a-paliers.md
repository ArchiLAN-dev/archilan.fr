# Story 41.68: Quêtes à paliers

**Status:** ready-for-dev
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-01

## Story

En tant que membre,
je veux une quête qui paye un peu au premier palier et plus aux suivants,
afin d'avoir un objectif à ma portée et une raison d'aller plus loin.

## Contexte

Suite de la 41.64. Aujourd'hui une quête est réussie ou non ; pour proposer « 1, 3 ou 5 goals », l'admin écrit
trois quêtes qui prennent trois places dans la semaine. Dépend de l'instantané de la 41.67 (paliers figés à la
mise en service).

## Critères d'acceptation

1. **Paliers** : une quête à un seul objectif peut avoir 2 ou 3 paliers, chacun avec sa cible (strictement
   croissante) et sa récompense. Une quête à plusieurs objectifs ou collective (41.69) n'a pas de paliers.
2. **Paiement** : chaque palier atteint est payé une fois, même si le membre en franchit plusieurs d'un coup. Le
   palier 1 garde la clé actuelle `quest:{semaine}:{quête}:{membre}` ; les suivants prennent une clé à part
   `quest-tier:{semaine}:{quête}:{membre}:{n}`, pour que le succès « Quêtes hebdo réussies »
   (`DbalQuestAchievementsQuery`, `LIKE 'quest:%'`) compte une quête une seule fois.
3. **Lecteurs du registre** : `payments()` (stats admin) et `earnedBy` (portefeuille) ajoutent les pelles des
   paliers à celles de la quête ; `rewardedQuests` reste sur le palier 1.
4. **Coffre** : une quête à paliers compte comme accomplie pour le coffre (41.16) dès son premier palier.
5. **Plafond** : la somme des paliers respecte `MAX_REWARD`.
6. **Affichage** : les paliers sur une même barre, ceux atteints cochés ; la notification de renouvellement
   (`maxPelles`) et l'annonce Discord comptent la récompense maximale (« jusqu'à 60 pelles »).
7. **Stats admin** : nombre de membres par palier la dernière fois que la quête a été servie.
8. Gates verts ; tests (plusieurs paliers d'un coup, idempotence, coffre, succès non gonflé, stats, `maxPelles`,
   validation) et des écrans.

## Notes techniques

- `QuestDefinition` : `tiers` (JSON nullable, liste de `{target, reward}`) ; `reward` et la cible de l'objectif
  sont ceux du palier 1, pour que le code qui ignore les paliers reste juste. Migration.
- Les paliers entrent dans l'instantané de l'entrée de semaine (41.67) ; avec une cible tirée, ils s'appliquent au
  jeu tiré.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1, 5) - Domaine, migration, validation ; tests unitaires.
- [ ] **Task 2** (AC 2, 3, 4) - Paiement par palier, lecteurs du registre, coffre ; tests.
- [ ] **Task 3** (AC 6, 7) - Affichage membre, notification, Discord, stats admin ; tests jest.
- [ ] **Task 4** (AC 8) - Gates, vérification dans l'app.
