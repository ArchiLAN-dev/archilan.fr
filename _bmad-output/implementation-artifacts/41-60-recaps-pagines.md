# Story 41.60: Les morceaux révélés, un par un, à la demande

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-26
**Dépôt :** `archilan-blind-test` (app seule)
**Origine :** Jean le 2026-10-26 : sur une playlist de 900 titres, le récap « tout ce que tu as trouvé » devient
énorme. Il veut une pagination morceau par morceau, faite par de vraies requêtes, pas un tri côté navigateur, pour que
peu de données transitent.

## Constat

Le téléphone interroge `/api/etat` toutes les 2 secondes. Chaque réponse porte jusqu'à 50 récaps complets
(`HISTORY_SIZE`), et le serveur charge pour les construire **tout** le journal d'antenne de la partie et **toutes** les
lignes `player_track` du joueur, à chaque appel.

## Critères d'acceptation

1. **État léger** : `/api/etat` ne porte plus de récaps. Il donne le nombre de morceaux révélés (`revealed`) et le
   numéro du dernier ; le reste de l'état (morceau en cours, score, rang, essais) est inchangé. Sa taille ne dépend
   plus de la longueur de la partie.
2. **Un récap par requête** : `/api/morceaux-reveles/{position}` (1 = le plus récent) renvoie le récap d'un seul
   morceau (numéro, trouvées, manquées, mes erreurs) et la position des voisins (`plus récent`, `plus ancien`,
   `null` aux bouts). Position hors bornes : 404.
3. **Requêtes bornées** : la position se résout en SQL sur le journal d'antenne (un morceau rejoué compte une fois, à
   son dernier passage ; le morceau à l'antenne et le précédent dans sa grâce sont exclus), avec `LIMIT 1 OFFSET n`.
   Seule la ligne `player_track` de ce morceau et de ce joueur est lue. Le compte de `revealed` est une requête
   `COUNT` du même ensemble.
4. **Téléphone** : la section « Morceaux révélés » devient une seule carte avec « ‹ plus récent » et « plus ancien › »
   et « Morceau 812 · 3 / 900 révélés ». Elle montre le plus récent par défaut ; quand un nouveau morceau est révélé,
   elle y revient seulement si le joueur regardait déjà le plus récent. Un récap déjà chargé est gardé en mémoire
   (un morceau révélé ne change plus), donc revenir en arrière ne refait pas de requête.
5. **Saut** : un champ « Aller au morceau n° » ouvre le récap d'un morceau révélé par son numéro dans la playlist
   (route `/api/morceaux-reveles/numero/{n}`, 404 s'il n'est pas révélé).
6. Tests : état sans récaps, première et dernière position, voisins, morceau rejoué compté une fois, morceau en grâce
   exclu, saut par numéro, 404. `composer gates` vert.

## Tâches

- [x] `GameService` : `playerState` sans `tracks`, avec `revealed` ; `revealedRecap(position)` et
      `revealedRecapOfNumber(n)` en requêtes bornées.
- [x] `PlayerController` : les deux routes.
- [x] `player/play.html.twig` : carte paginée, cache des récaps, saut par numéro.
- [x] Tests ; `composer gates`.

## Notes

- S'appuie sur 41.58 (journal et lignes par partie) : les requêtes filtrent sur la partie active.
- Index utile : `on_air_entry (game_id, id)`.

## Notes de réalisation (2026-10-26)

- App : PR #18 (empilée sur #17). L'état porte `recaps: {count, latest}`.
- Le cache du téléphone est vidé à chaque morceau révélé (les places glissent) ; la carte suit le dernier si on le regardait.
