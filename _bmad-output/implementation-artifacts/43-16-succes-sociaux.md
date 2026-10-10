# Story 43.16: Succès sociaux

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que joueur,
je veux débloquer des succès liés au fait de jouer avec d'autres,
afin d'être récompensé quand je joue en groupe.

## Contexte

Les succès sont des **règles sur des faits**, éditables par l'admin (story 30.16) : `AchievementDefinition`,
`AchievementRuleCondition` (`fact` comparé à un seuil), `MetricBag` alimenté par
`AchievementMetricProviderInterface` / `MetricBagBuilder`, recalcul rétroactif par `RecomputeAchievements`.
Ajouter un succès social = **ajouter des faits**, pas du code d'attribution. Noms dans le style maison
(références pop-culture / ciné).

## Critères d'acceptation

1. Nouveaux faits exposés par le fournisseur de métriques :
   - `distinct_coplayers` : nombre de personnes différentes avec qui l'utilisateur a joué (runs perso, événements) ;
   - `distinct_friends_played_with` : idem, restreint aux amis acceptés ;
   - `max_finished_with_same_person` : plus grand nombre de parties terminées avec une même personne ;
   - `weekly_duels_won` (si 43.15 est livrée).
2. Définitions par défaut ajoutées (noms à valider par Jean) :
   - 5 amis différents : « Les Goonies » ;
   - 3 parties finies avec la même personne : « L'Arme fatale » ;
   - 1 duel hebdo gagné : « Il ne peut en rester qu'un ».
3. Rétroactivité : `RecomputeAchievements` attribue les succès calculables depuis l'historique ; la notification
   `achievement_unlocked` existante part pour les nouvelles attributions.
4. L'admin peut composer de nouvelles règles avec ces faits sans code.
5. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Application/Infrastructure** : faits dans le fournisseur de métriques (réutiliser l'agrégat de
      co-participations de 43.2).
- [x] **Domaine** : définitions par défaut (`DefaultAchievementDefinitions`).
- [x] **Front** : libellés des nouveaux faits dans l'éditeur de règles admin.
- [x] Tests (calcul des faits, attribution rétroactive) et gates.

## Notes

- `distinct_friends_played_with` dépend du graphe d'amis actuel : retirer un ami peut faire baisser le fait ; un
  succès déjà attribué n'est pas retiré (comportement existant à confirmer).
- Idée écartée : « sortir un ami du BK » ; le fait dépend de 43.10, à ajouter une fois l'historique des BK en place.

## Dev Notes

- **Faits** (camelCase comme les autres clés du catalogue, et non le snake_case de la story) :
  `distinctCoplayers`, `distinctFriendsPlayedWith`, `maxFinishedWithSamePerson`, `weeklyDuelsWon`, dans
  `AchievementMetricCatalog` (libellés admin). Fournis par `SocialPlayMetricProvider` (tag
  `community.achievement_metric_provider`) via `SocialPlayQueryInterface` / `DbalSocialPlayQuery` : même agrégat que
  la 43.2 (joueurs des `session_slot`, propriétaires et co-joueurs, runs perso et événements ; les hebdos n'ont pas
  de slot de session). « Terminée » = `session.finished_at` renseigné. `weeklyDuelsWon` compte `weekly_duel.winner_id`.
- **Amis** : `distinctFriendsPlayedWith` lit les amitiés du jour ; retirer un ami peut faire baisser le fait, un
  succès déjà attribué reste (attribution monotone, comportement existant).
- **Définitions** : `Domain/Service/SocialAchievementDefinitions` (même modèle que les quêtes 41.20), semées par
  `Version20261010160000` (idempotente, `ON CONFLICT DO NOTHING`) : « Les Goonies » (5 amis différents), « L'Arme
  fatale » (3 parties terminées avec la même personne), « Il ne peut en rester qu'un » (1 duel hebdo gagné). Noms
  à valider par Jean ; modifiables ensuite dans l'admin.
- **Rétroactivité** : le recalcul horaire attribue sans notifier (comportement existant). Pour que la notification
  `achievement_unlocked` parte sur l'historique, `community:achievements:recompute` gagne `--notify`, à lancer une
  fois juste après la migration.
- **Front** : famille « Jouer ensemble » (icône Users) dans l'éditeur de règles, phrasés des quatre faits.
- **Tests** : `SocialAchievementsTest` (calcul des faits, attribution + notification, faits connus) ; tests front
  des phrasés et de la famille.
- **A déployer** : `Version20261010160000`, puis `php bin/console community:achievements:recompute --notify`.
