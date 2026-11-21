# Story 56.6 - Plafond de part de progression dans l'image archipelago

**Status:** ready-for-dev (essai sur banc en premier)
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1179
**Dépend de:** 56.1 (mesure de la part hébergée, pour vérifier l'effet). Touche l'image archipelago (dépôt séparé,
PR sobre).

## Story

En tant qu'admin d'un event,
je veux qu'un monde géant ne puisse pas détenir la plupart des clés des autres joueurs,
afin que la partie ne dépende plus du joueur qui a le plus gros monde.

## Décisions

1. **Mécanisme** : avant le remplissage, pour chaque monde dont la part des emplacements dépasse `k ×` la part
   équitable (`1 ÷ nombre de mondes`), on marque `LocationProgressType.EXCLUDED` une sélection aléatoire (graine de
   la génération) de ses emplacements encore `DEFAULT`, jusqu'à ramener ses emplacements capables de recevoir de la
   progression à `k ×` la part équitable. `k` vaut 2 par défaut.
2. Un emplacement exclu ne reçoit ni progression ni objet utile ; le joueur du monde géant garde tous ses checks.
3. **Garde-fou** : on n'exclut jamais au point que le monde ne puisse plus loger sa propre progression locale, ni
   au-delà du remplissage disponible. Si la limite ne peut pas être atteinte, on exclut ce qui est possible et on le
   journalise.
4. Option de génération côté orchestrateur, **désactivée par défaut**, activable par event (et par partie privée).
5. **Essai d'abord** : sur le banc de génération local, avec les jeux des derniers events, mesurer le taux d'échec de
   génération et la part hébergée avant et après. Pas de mise en prod sans cet essai.

## Critères d'acceptation

1. Avec l'option active, la part hébergée d'un monde géant (mesurée par 56.1) ne dépasse plus `k ×` la part
   équitable, sauf garde-fou journalisé.
2. Sans l'option, la génération est identique à aujourd'hui (même graine, même résultat).
3. L'essai sur banc est consigné dans le Dev Agent Record : jeux, taux d'échec, parts avant et après.

## Tests et gates

4. Image : tests de la sélection (déterministe par graine, garde-fous) sur une multiworld de test. Orchestrateur :
   passage de l'option. API : réglage par event.
5. Gates verts sur les trois dépôts concernés.

## Tasks / Subtasks

- [ ] **Task 1** - Essai sur le banc de génération local (Python 3.12, cf. mémoire `yaml_roundtrip_lossy`).
- [ ] **Task 2** - Hook de génération dans l'image archipelago (après `generate_basic`, avant le remplissage).
- [ ] **Task 3** - Option côté orchestrateur et réglage par event dans l'API et l'admin.
- [ ] **Task 4** - Tests et gates.

## Dev Notes

- Contexte : `Fill.py`, `distribute_items_restrictive` mélange les emplacements de tous les mondes et pose chaque
  objet au premier emplacement accessible ; aucune pondération par monde.
- Les objets d'un monde exclu basculent vers du remplissage : vérifier les jeux qui n'en ont pas assez (même famille
  de piège que les 93 coffres exclus d'OoT Remake, issue Mozencaz/OotrR#83).
