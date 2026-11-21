# Story 56.7 - Réglage automatique des options de taille

**Status:** ready-for-dev
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1180
**Dépend de:** 56.5 (durée cible, test joueur).

## Story

En tant que joueur dont le monde dépasse la durée cible,
je veux qu'on me propose une valeur d'option qui ramène mon monde à la bonne taille,
afin de ne pas avoir à deviner quelle option change quoi.

## Décisions

1. **Options candidates** : options numériques (`Range`) d'un jeu, désignées par un admin dans la curation comme
   « change la taille du monde ». Aucune détection automatique au départ.
2. **Recherche** : par dichotomie sur une option à la fois, avec des tests de slot, jusqu'à une durée estimée dans
   ±10 % de la cible, ou au plus 6 générations.
3. **Proposition** : « avec `nombre_de_jetons` à 40 au lieu de 120 : environ 3 h 50 ». Le joueur accepte (la valeur
   est appliquée à son YAML) ou ignore.
4. La recherche tourne en tâche de fond (Messenger) et compte dans la limite de tests de 56.5.

## Critères d'acceptation

1. Un admin désigne les options de taille d'un jeu.
2. Pour un slot au-delà de la cible, la recherche propose une valeur dans ±10 % de la cible, ou dit qu'elle n'a pas
   trouvé en 6 générations.
3. Rien n'est appliqué sans l'accord du joueur.

## Tests et gates

4. Unitaires de la dichotomie (passerelle de test simulée, fonction de taille monotone ou non). Fonctionnels :
   désignation admin, proposition, acceptation. Front.
5. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** - Désignation des options de taille dans la curation d'un jeu.
- [ ] **Task 2** - Recherche en tâche de fond, résultat stocké avec le slot.
- [ ] **Task 3** - Proposition et acceptation côté joueur.
- [ ] **Task 4** - Tests et gates.
