# Story 5.8: Le détail d'inscription admin affiche le YAML du joueur

**Status:** review
**Epic:** 5 - Administration des inscriptions
**Date:** 2026-10-24
**Origine :** signalement de Jean le 2026-10-24 : ouvrir l'inscription d'un joueur sur un event plante dès qu'il a
des jeux configurés.

## Story

En tant qu'admin d'un événement,
je veux ouvrir le détail d'une inscription qui a des jeux,
afin de voir ses jeux et la configuration YAML de chaque slot sans que la page plante.

## Contexte

- La story 12.1 a remplacé les options de randomizer par un YAML par slot. L'API
  (`AdminRegistrationInspector`) renvoie depuis, pour chaque jeu, `playerYaml` et plus de liste `options`.
- L'écran `admin-registration-detail.tsx` (story 5.2) lit encore `game.options` : `undefined.filter` fait planter
  le rendu dès qu'une inscription a au moins un jeu. Une inscription sans jeu s'affiche.

## Critères d'acceptation

1. Le détail d'une inscription avec des jeux s'affiche, une carte par slot (dans l'ordre des slots).
2. Chaque carte montre le jeu, son état (complet ou non), ses avertissements, et le YAML du joueur en lecture
   seule ; sans YAML : « Aucun YAML enregistré pour ce slot. ».
3. Statut d'une carte incomplète sans avertissement : « YAML manquant ».
4. Le contrôle de la réponse vérifie la forme de chaque jeu (une réponse inattendue affiche l'erreur de chargement,
   pas un plantage).
5. Aucune modification de l'API.

## Tâches

- [x] Front : type `GameDetail` aligné sur l'API (`playerYaml`), carte YAML, garde de type par jeu.
- [x] Tests : garde de type et rendu d'une carte avec et sans YAML.
