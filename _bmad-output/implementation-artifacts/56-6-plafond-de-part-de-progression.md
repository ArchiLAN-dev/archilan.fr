# Story 56.6 - Plafond de part de progression dans l'image archipelago

**Status:** in-progress (essai fait, image en PR, option a brancher)
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

- [x] **Task 1** - Essai sur le banc de génération local (Python 3.12, cf. mémoire `yaml_roundtrip_lossy`).
- [x] **Task 2** - Hook de génération dans l'image archipelago (après `generate_basic`, avant le remplissage).
- [ ] **Task 3** - Option côté orchestrateur et réglage par event dans l'API et l'admin.
- [ ] **Task 4** - Tests et gates.

## Dev Notes

- Contexte : `Fill.py`, `distribute_items_restrictive` mélange les emplacements de tous les mondes et pose chaque
  objet au premier emplacement accessible ; aucune pondération par monde.
- Les objets d'un monde exclu basculent vers du remplissage : vérifier les jeux qui n'en ont pas assez (même famille
  de piège que les 93 coffres exclus d'OoT Remake, issue Mozencaz/OotrR#83).

## Dev Agent Record

### Essai sur banc (2026-11-22)

- Banc : image `archipelago:latest` locale (AP 0.6.8) avec les scripts du dépôt montés, réseau coupé, mesures 56.1.
- Jeux (officiels, sans ROM) : Stardew Valley (shipsanity everything, cooksanity, craftsanity, friendsanity,
  museumsanity : 1498 emplacements, 70 % du total), Hollow Knight (203), Timespinner (180), Subnautica (131),
  Muse Dash (90), DLCQuest (29). Les jeux des derniers events sont des apworlds maison absents du poste.
- 8 graines sans l'option, 8 avec `k = 2` :

  | | Échecs | Part hébergée du monde géant (min / médiane / max) |
  |---|---|---|
  | Sans plafond | 0 / 8 | 46,1 % / 47,4 % / 51,2 % |
  | `k = 2` | 0 / 8 | 21,8 % / 24,5 % / 25,5 % |

  Part équitable : 16,7 % (6 mondes), plafond visé 33,3 %. Aucun avertissement d'accessibilité.
- Le garde-fou du remplissage a joué à chaque graine : environ 1000 emplacements exclus sur les 1184 voulus (autant
  que d'objets de remplissage disponibles). Conséquence à assumer : les deux tiers des checks du monde géant ne
  donnent plus que du remplissage.
- Sans l'option : même graine, même placement que l'image actuelle (spoiler comparé, seed 1).

### Image (PR archilan-archipelago #57)

- `progression_cap.py` : `cap_factor` (variable `ARCHILAN_PROGRESSION_CAP`, éteint si absente ou <= 1),
  `capable_target` (point fixe : exclure baisse le total donc la part équitable), `plan_exclusions` (tirage par
  graine, garde-fous progression locale et remplissage), `apply_progression_cap`, `install` (enveloppe
  `Main.distribute_items_restrictive`, donc après tous les `pre_fill`). L'observateur Bridge ne compte pas.
- `reachable.py` n'est pas touché : il rejoue jusqu'avant le remplissage et lit le placement dans le spoiler.

### Reste (Task 3)

- Orchestrateur : passer `ARCHILAN_PROGRESSION_CAP` au conteneur de génération depuis les options de génération.
- API et admin : réglage par event et par partie privée, éteint par défaut.
