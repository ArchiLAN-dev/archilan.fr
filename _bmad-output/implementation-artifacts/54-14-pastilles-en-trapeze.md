# Story 54.14 - Pastilles en trapèze

**Status:** review
**Epic:** 54 - Trackers PopTracker
**Date:** 2026-11-23
**Dépend de:** 54.5 (écran du tracker), 54.9 (pastilles comme PopTracker), 54.11 (parité PopTracker 0.35.4).

## Story

En tant que joueur qui suit sa partie sur le tracker du site,
je veux que les emplacements d'un pack aient la forme que le pack leur donne,
afin de reconnaître d'un coup d'œil un donjon d'un emplacement simple, comme dans PopTracker.

## Contexte

Une carte PopTracker donne une forme à ses pastilles : `shape` sur l'emplacement, `location_shape` sur la carte.
PopTracker 0.35.4, la version que le moteur annonce (`EMULATED_POPTRACKER_VERSION`), en dessine trois : `rect` (le
défaut), `diamond` et `trapezoid`. Le site dessine le losange depuis 54.9, mais un trapèze devient un carré.

Le pack Minish Cap (deoxis9001, 1.0.0.50) met ses 56 emplacements de donjon (`Dungeons.json`) en `trapezoid` : sur
le site, les donjons ne se distinguent plus des autres emplacements, alors qu'ils le font dans PopTracker.

Dessin de PopTracker (`src/uilib/drawhelper.cpp`, `drawTrapezoid`) : bas sur toute la largeur, haut du quart aux
trois quarts de la largeur, bordure noire de l'épaisseur de la pastille, intérieur coupé en quatre parts (haut,
gauche, bas, droite) qui se rejoignent au centre et vont aux quatre coins du trapèze.

## Critères d'acceptation

1. Un emplacement en `shape: "trapezoid"`, ou sur une carte en `location_shape: "trapezoid"` sans forme propre, est
   dessiné en trapèze : bas sur toute la largeur, haut du quart aux trois quarts, dans l'emprise carrée de la
   pastille (`size`).
2. La bordure suit le trapèze, de l'épaisseur de la pastille (`border_thickness`), de la même couleur que celle des
   autres pastilles.
3. Un emplacement dont les sections diffèrent garde ses quatre parts de couleur, coupées du centre vers les coins du
   trapèze, dans le même ordre que le carré (haut, droite, bas, gauche).
4. La zone de clic élargie, le contour de sélection et la pastille d'indice restent ceux des autres formes : rien
   n'est rogné par la forme.
5. Le carré et le losange ne changent pas.

## Tâches

- [x] Couleurs d'une pastille selon sa forme : angles de coupe du trapèze (`tracker-view.ts`), tests.
- [x] Rendu du trapèze dans `MapPin` (`tracker-map.tsx`) : bordure et intérieur découpés, le bouton reste carré pour
      la zone de clic, le contour et l'indice.
- [x] Test d'écran : une pastille en trapèze, entière et en parts.
- [x] Gates front verts.

## Notes de dev

- Le découpage passe par `clip-path: polygon(...)` sur deux couches à l'intérieur du bouton. Le poser sur le bouton
  lui-même rognerait la zone de clic (`::before`), le contour et la pastille d'indice.
- Angles du dégradé conique pour un trapèze dans un carré, mesurés depuis le haut : les coins hauts sont à
  ±atan(1/2) ≈ ±26,57°, les coins bas à ±135°.

## Dev Agent Record

- `pinShape` ramène une forme de pack à `rect`, `diamond` ou `trapezoid` ; `pinBackground(marks, shape)` coupe le
  trapèze à ±26,565° et ±135°.
- `TrapezoidLayers` (`tracker-map.tsx`) : deux couches `clip-path` dans le bouton, qui garde sa taille carrée, sa
  zone de clic, son contour et sa pastille d'indice. Le haut de l'intérieur part du bord intérieur de la bordure,
  comme `drawTrapezoid`.
- Tests : angles et formes (`tracker-ui-logic.test.ts`), pastille en trapèze par `shape` et par `location_shape`, le
  losange inchangé (`tracker-widgets.test.tsx`). Rendu vérifié dans le navigateur (entier, en parts, à 20 px).
- Gates front verts.
