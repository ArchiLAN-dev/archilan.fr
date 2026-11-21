# Story 56.2 - Rythme de référence par jeu et par joueur

**Status:** ready-for-dev
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1175
**Dépend de:** 56.1 (taille d'un slot gardée après la partie).

## Story

En tant qu'admin,
je veux une durée estimée pour chaque slot, calculée sur nos parties passées,
afin de comparer des mondes de jeux différents sur la même échelle.

## Décisions

1. **Rythme d'un jeu** : médiane des checks par heure sur les slots qui ont atteint le goal, avec
   `checks par heure = checks_done ÷ (goal_reached_at - début de la session)`. Un slot en pause n'est pas corrigé
   (approximation assumée et affichée). Minimum de 3 slots pour qu'un rythme mesuré soit utilisé.
2. **Facteur d'un joueur** : moyenne, sur ses slots terminés, du rapport entre son rythme et le rythme du jeu ;
   ramené vers 1 par pondération `n ÷ (n + 3)` (n = nombre de ses slots terminés). Borné entre 0,5 et 2.
3. **Repli admin** : sur la fiche d'un jeu, un champ « checks par heure estimés » optionnel, utilisé quand le jeu n'a
   pas assez de parties mesurées.
4. **Durée estimée d'un slot** = `locations ÷ (rythme du jeu × facteur du joueur)`, avec sa provenance : `measured`
   (N slots), `admin`, ou `unknown` (pas de durée, taille seule).
5. Calcul à la lecture, en DBAL côté Infrastructure, mis en cache (pool dédié, 1 h) : il ne dépend que des parties
   terminées.
6. Les hebdos (`weekly_entry`) comptent dans le rythme d'un jeu.

## Critères d'acceptation

1. Le rythme d'un jeu suit la médiane des slots terminés, avec au moins 3 slots, sinon il replie sur la saisie admin,
   sinon il est inconnu.
2. Le facteur d'un joueur sans historique vaut 1, et s'en éloigne à mesure de ses parties, dans les bornes.
3. La durée estimée renvoie sa provenance et le nombre de slots qui la fondent.
4. Le champ admin est éditable sur la fiche du jeu.

## Tests et gates

5. Fonctionnels : médiane, seuil de 3, repli, facteur borné et pondéré. Front : champ admin.
6. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** - Colonne `games.estimated_checks_per_hour` (nullable), champ admin.
- [ ] **Task 2** - `GamePaceQueryInterface` (Application) et `DbalGamePaceQuery` (Infrastructure), cache.
- [ ] **Task 3** - Service `SlotDurationEstimate` (Application) : durée, provenance.
- [ ] **Task 4** - Tests et gates.
