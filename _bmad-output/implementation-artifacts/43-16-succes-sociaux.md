# Story 43.16: Succès sociaux

**Status:** draft
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

- [ ] **Application/Infrastructure** : faits dans le fournisseur de métriques (réutiliser l'agrégat de
      co-participations de 43.2).
- [ ] **Domaine** : définitions par défaut (`DefaultAchievementDefinitions`).
- [ ] **Front** : libellés des nouveaux faits dans l'éditeur de règles admin.
- [ ] Tests (calcul des faits, attribution rétroactive) et gates.

## Notes

- `distinct_friends_played_with` dépend du graphe d'amis actuel : retirer un ami peut faire baisser le fait ; un
  succès déjà attribué n'est pas retiré (comportement existant à confirmer).
- Idée écartée : « sortir un ami du BK » ; le fait dépend de 43.10, à ajouter une fois l'historique des BK en place.
