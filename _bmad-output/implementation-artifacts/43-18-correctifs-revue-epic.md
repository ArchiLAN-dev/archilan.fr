# Story 43.18: Correctifs de la revue de l'epic 43 (avant release)

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-10
**Dépend de:** 43.14, 43.15, 43.16, 43.17

## Story

En tant qu'équipe ArchiLAN,
je veux corriger les défauts relevés par la revue de l'epic 43 qui touchent l'exactitude ou la modération,
afin de pouvoir releaser l'epic sans résultat de duel faux, succès muet ni annonce impossible à modérer.

## Contexte

Revue de l'epic 43 (2026-10-10, quatre relecteurs sur `c2447a2b..f2224dbc`) : aucun défaut grave (pas de fuite ni de
faille d'autorisation), six défauts à corriger avant la release. Le reste des constats va dans une story 43.19.

## Critères d'acceptation

1. **Résolution des duels rattrapable** (43.15) : la résolution ne dépend plus d'un seul passage dans
   `StopWeeklyRunsMessageHandler`. Une tâche planifiée résout les duels non résolus des hebdos terminées ; un échec
   sur une hebdo n'empêche ni les autres ni le passage suivant de la reprendre.
2. **Délai de grâce** (43.15) : un duel n'est résolu qu'un moment après la fin de son hebdo, pour que les goals
   atteints avant la fin mais reçus en retard comptent.
3. **Égalité** (43.15) : deux meilleurs temps égaux à l'objectif ne désignent pas de gagnant ; les ex aequo reçoivent
   un résultat « égalité », pas d'entrée d'activité. Test d'égalité ajouté.
4. **Succès sociaux notifiés** (43.16) : gagner un duel et accepter une amitié relancent le calcul des succès des
   membres concernés (avec notification). Les succès sociaux par défaut sont semés inactifs ; la commande de recalcul
   les active puis attribue avec notification, de sorte que le recalcul horaire ne puisse pas les attribuer en silence
   avant elle. La migration n'importe plus de classe du code source.
5. **Places respectées** (43.14, 43.17) : deux arrivées simultanées sur la dernière place n'en font entrer qu'une
   (verrou sur la run le temps du décompte et de l'inscription).
6. **Preuve du signalement** (43.17) : le texte de l'annonce (titre et message) est copié dans le signalement au moment
   où il est fait ; la file de modération montre cette copie même si l'annonce a changé ou a été retirée depuis.
7. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] Duels : tâche planifiée de résolution (hebdos terminées depuis plus du délai de grâce), retrait de l'appel
      dans `StopWeeklyRunsMessageHandler`, égalité, recalcul des succès après résolution.
- [x] Amitié acceptée : recalcul des succès des deux membres.
- [x] Succès sociaux : migration sans import de classe, semés inactifs ; option d'activation de la commande.
- [x] Jointure : verrou de la run autour du décompte des places.
- [x] Signalement : copie de l'annonce dans `ContentReport`, affichée en modération.
- [x] Front : résultat « égalité » dans la cloche.
- [x] Tests et gates.

## Dev Notes

- **Duels** : `ResolveWeeklyDuelsMessage` toutes les 15 minutes, `ResolveWeeklyDuelsHandler` : hebdos `finished`
  depuis plus de `GRACE` (15 min) ayant encore un duel non résolu (`WeeklyDuelContextQueryInterface::finishedRunsWithOpenDuels`),
  une par une, chaque échec journalisé sans bloquer les autres ni le passage suivant. L'appel ajouté par la 43.15 dans
  `StopWeeklyRunsMessageHandler` est retiré (handler et son test unitaire revenus à leur état d'avant).
- **Égalité** : `ResolveWeeklyDuels::leaders` (membres à l'objectif avec le meilleur temps) ; un seul = gagnant,
  plusieurs = pas de gagnant, résultat `tie` aux ex aequo (adversaire nommé = un autre ex aequo), `lost` aux autres
  (écart avec les premiers), pas d'entrée d'activité. Cloche : « Égalité avec X pour ton duel hebdo sur Y ».
- **Succès** : `AchievementRecomputeTriggerInterface::recomputeForUsers` (recalcul avec notification) pour les
  gagnants après la résolution, et pour les deux membres quand une amitié est acceptée (`FriendshipService::accept`).
  `Version20261010160000` (non releasée, modifiable) ne dépend plus de `SocialAchievementDefinitions` : données en dur,
  semées **inactives**. `community:achievements:recompute` gagne `--activate=<clés>` (`ActivateAchievements`),
  appliqué avant le recalcul.
- **Places** : `RunRepositoryInterface::findWithExclusiveLock` + `beginTransaction/commit/rollBack` (même motif que les
  inscriptions aux événements). `JoinOpenRun` relit la run verrouillée, revérifie l'ouverture, compte les places et
  inscrit dans la même transaction ; notification au propriétaire après le commit. Au passage : l'annonce d'un
  propriétaire suspendu ne se rejoint plus par un ancien lien (point 7 de la revue des runs).
- **Signalement** : `ContentReport.target_snapshot` (JSON, `Version20261010180000`) ; `ReportRunListingService` y copie
  titre et message. La file de modération montre la copie et `changedSince` quand l'annonce a changé ou disparu.
- **Tests** : `WeeklyDuelTest` (+3 : recalcul du gagnant, délai de grâce puis rattrapage, égalité), `SocialAchievementsTest`
  (+2 : commande `--activate --notify`, amitié acceptée), `RunListingsTest` (+1 et copie du signalement) ;
  `weekly-duels.test.tsx` (égalité). La concurrence réelle n'est pas reproductible en PHPUnit : le test des places
  couvre le chemin verrouillé en séquence.
- **A déployer** : `Version20261010180000` ; puis, une fois toutes les migrations passées :
  `php bin/console community:achievements:recompute --notify --activate=friends_played_5,same_partner_3,weekly_duel_won`.
  La base de dev avait déjà les succès sociaux actifs (ancienne version de la migration) : sans effet pour la prod.
