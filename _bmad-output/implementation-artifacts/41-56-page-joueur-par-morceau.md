# Story 41.56: La page joueur, une carte par morceau

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-26
**Dépôt :** `ArchiLAN-dev/archilan-blind-test` (app)
**Origine :** Jean le 2026-10-26 : les cartes de la page joueur sont posées « au pif ». Proposition retenue après une
recherche (Kahoot, Jackbox, Sporcle) : fusionner, par morceau, ce que j'ai trouvé, ce qui m'a manqué et mes erreurs,
plutôt que des cartes séparées.

## Story

En tant que joueur sur mon téléphone,
je veux voir chaque morceau en une carte (trouvé, manqué, mes erreurs) sous un champ de proposition toujours en haut,
afin de comprendre d'un coup d'œil où j'en suis et ce que j'ai raté.

## Critères d'acceptation

1. **Bandeau** en haut de page : nom, total trouvé, rang, morceau « n / total », et 5 points d'essais (pleins = ratés
   sur le morceau en cours).
2. **Saisie en premier** : le champ de proposition vient juste après le bandeau. Le verdict s'affiche sous le champ.
   Plus d'essai : champ et bouton grisés avec « Plus d'essai sur ce morceau, attends le suivant ». Trop vite : le
   bouton affiche un compte à rebours de 2 s.
3. **Morceau en cours** : une carte avec mes trouvailles (vert) et mes erreurs (barrées). Aucune réponse manquée n'y
   paraît.
4. **Morceaux révélés** : un morceau passé à l'antenne et sorti de sa grâce devient une carte de récap : chaque
   réponse marquée trouvée ou manquée, puis mes erreurs sur ce morceau. Le dernier révélé est ouvert, les précédents
   sont repliés en une ligne « n°5 · 2/3 · 1 erreur » qui s'ouvre au toucher. Ordre : le plus récent d'abord.
5. **Erreurs gardées** : le nom de chaque proposition fausse est gardé par joueur et par morceau (colonne JSON
   `player_track.misses`, migration). Elles restent privées : seul le joueur les voit, l'écran de salle ne change pas.
6. **Pas d'avance** : les réponses d'un morceau ne partent vers le téléphone qu'une fois révélées (à l'antenne avant,
   sorti de la grâce). Les morceaux révélés viennent du journal d'antenne (`on_air_entry`).
7. Les cartes « Trouvé sur ce morceau », « Réponses du morceau N » et « Tout ce que tu as trouvé » disparaissent ;
   l'ordre de la page ne bouge plus au rafraîchissement, et une carte ouverte le reste.

## Tâches

- [x] `PlayerTrackState::misses`, `PlayerTrack.misses` + migration ; `GameService::propose` les remplit.
- [x] `GameService::playerState` : `tries`, `misses`, `tracks` (récaps des morceaux révélés) à la place de `history`.
- [x] `templates/player/play.html.twig` refait.
- [x] Tests `GameTest` ; `composer gates`.

## Hors périmètre

- Écran de salle et régie.
- Partage du récap (grille façon Heardle).

## Dev Agent Record

- App : branche `feature/41-56-page-joueur`, migration `Version20261026090000` (`player_track.misses`), appliquée à la
  base locale de l'app.
- `playerState` : `history` remplacé par `tracks` (récap par morceau révélé, d'après `on_air_entry`), plus `misses` et
  `maxWrong`. Une erreur faite pendant la grâce du morceau précédent compte sur le morceau en cours, comme son essai.
- Page : bandeau (nom, total, rang, morceau, 5 points d'essais), saisie, carte du morceau en cours, puis récaps
  (`<details>`, le plus récent ouvert, l'état ouvert ou fermé gardé entre deux rafraîchissements).
