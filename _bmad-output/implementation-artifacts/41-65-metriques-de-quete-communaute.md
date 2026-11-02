# Story 41.65: Métriques de quête - communauté et économie

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-01

## Story

En tant qu'admin,
je veux des quêtes sur les duels, les récaps, les amis, les primes et le blind test,
afin que les quêtes poussent aussi à jouer ensemble et à faire vivre le reste du site.

## Contexte

Suite de la 41.64 (même demande de Jean, 2026-11-01). Ces métriques se lisent dans des tables déjà datées.

Écarté : « sortir quelqu'un de BK ». `session_slot_block` ne garde que l'épisode en cours et rien ne dit quel
joueur a débloqué l'autre ; il faudrait d'abord stocker les épisodes clos et une attribution.

Chaque métrique a son garde-fou contre un compte bis : une quête paye du jeu réel, jamais une action gratuite.

## Critères d'acceptation

1. **`duelsWon` « Duels hebdo gagnés »** : duels (`weekly_duel`) dont le membre est `winner_id` et résolus
   (`resolved_at`) dans la semaine, et où au moins un autre participant a fait un check dans l'hebdo (un adversaire
   qui accepte puis ne joue pas ne donne rien). Un duel se résout à la fin de son hebdo : l'aide du formulaire
   prévient qu'il peut compter la semaine suivante.
2. **`superlatives` « Superlatifs de récap remportés »** : superlatifs du récap (`session_recap.superlatives`), mêmes
   conditions que le succès (`DbalRecapSuperlativesQuery`), mais avec **au moins 3 membres** dans la partie (le
   succès en demande 2 : à deux, un compte bis rafle tout). La semaine est celle de la **construction du récap**,
   pas celle de la fin de la session : un récap construit en retard compte la semaine où il apparaît, et la tâche
   de paiement, qui couvre la semaine en cours et la précédente, ne le perd pas.
3. **`friendsPlayedWith` « Amis avec qui jouer »** : amis acceptés (`community_friendship`, état lu au moment du
   calcul) distincts avec qui le membre a partagé une session où chacun a fait au moins un check dans la semaine
   (même définition de « jouer ensemble » que `newPartners`). Une amitié rompue après coup ne reprend rien de déjà
   payé (paiement idempotent).
4. **`returningPartners` « Partenaires retrouvés »** : le pendant de `newPartners`, des membres avec qui il avait
   déjà joué avant la semaine.
5. **`bountiesWon` « Primes remportées »** : primes (`item_bounty`) dont le membre est `winner_id`, réglées
   (`settled_at`) dans la semaine, en **or seulement** (`run_id IS NULL` : une prime en bronze de partie privée est
   gratuite) et posées par un autre membre.
6. **`blindTestSources` « Sources trouvées au blind test »** : mouvements du registre de motif `blind_test` crédités
   au membre dans la semaine (une source trouvée = un mouvement, clé
   `blindtest:{event}:{track}:{source}:{user}`). L'aide précise que la métrique n'existe que pendant un blind test
   d'event.
7. **Ciblage** : seule `superlatives` accepte une cible événement ; les autres n'en acceptent pas.
8. **Pas de quête qui fait dépenser** : aucune métrique sur les achats (indices, boutique).
9. **Admin et membre** : formulaire, aide d'une ligne, libellés, barre de progression, notification et Discord,
   comme en 41.64.
10. Gates verts ; tests de chaque compteur (fenêtre, adversaire de duel sans check, partie à 2, récap tardif, ami
    non accepté, prime en bronze, prime auto-posée) et des écrans.

## Notes techniques

- `returningPartners` réutilise la CTE de `newPartners` (`this_week` / `before`) avec la condition inversée.
- `superlatives` : vérifier la colonne de date de construction de `session_recap` ; décodage du JSON recopié de
  `DbalRecapSuperlativesQuery` avec un test de parité (le Wallet n'importe pas Community).

## Tasks / Subtasks

- [x] **Task 1** (AC 1-6) - Cas `QuestMetric` et compteurs SQL ; tests fonctionnels par compteur.
- [x] **Task 2** (AC 7, 8) - Ciblage, validation ; tests.
- [x] **Task 3** (AC 9) - Formulaire, affichages, Discord ; tests jest.
- [x] **Task 4** (AC 10) - Gates, vérification dans l'app.

## Dev Agent Record

### Notes

- `QuestMetric` gagne `duelsWon`, `superlatives`, `friendsPlayedWith`, `returningPartners`, `bountiesWon`,
  `blindTestSources`, et `scopes()` (cibles permises par métrique) qui remplace `QuestObjective::SCOPABLE` :
  les superlatifs n'acceptent qu'un événement. Le payload admin expose `scopes`, le formulaire ne propose que ces
  cibles (`aimableScopes`).
- `newPartners`, `returningPartners` et `friendsPlayedWith` partagent une requête (`partners`), seule la condition
  sur la paire change.
- Duels : un participant accepté autre que le gagnant doit avoir une tentative avec des checks (`checks_total` ou
  flux de sa session). Le créateur est lui-même un participant accepté.
- Superlatifs : date = `session_recap.generated_at` ; mêmes sessions que le succès, au moins 3 membres
  (`MIN_SUPERLATIVE_PLAYERS`) ; décodage du JSON en PHP, une fois par (membre, session, superlatif). Pas de
  test de parité : la règle diffère volontairement du succès (2 membres).
- Primes : `status = paid`, `run_id IS NULL`, `winner_id <> poster_id`, `settled_at` dans la semaine.
- Blind test : mouvements `blind_test` de clé `blindtest:%` créés dans la semaine.

### File List

- `api/src/Wallet/Domain/Enum/QuestMetric.php`, `api/src/Wallet/Domain/ValueObject/QuestObjective.php`
- `api/src/Wallet/Application/Query/QuestAdminQuery.php`
- `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php`
- `api/tests/Functional/QuestCommunityMetricsTest.php` (nouveau), `api/tests/Unit/Wallet/QuestMetricTest.php`
- `frontend/src/features/wallet/admin-quests-api.ts`, `admin-quests-page.tsx` (+ test)
