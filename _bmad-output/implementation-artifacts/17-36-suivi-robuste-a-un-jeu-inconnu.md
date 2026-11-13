# Story 17.36: Un jeu que le suivi ne connaît pas ne casse plus toute la partie

**Status:** in-progress (hotfix v0.66.2 : AC 1 à 3 livrés ; AC 4 et 5 restent)
**Epic:** 17 - Sessions
**Date:** 2026-11-13

## Story

En tant que joueur (et admin) d'une grande partie,
je veux que le suivi « faisable » marche pour tous les slots même si l'un des jeux ne peut pas être rejoué,
afin qu'un seul YAML ne prive pas 110 joueurs du suivi, des sphères et du Passeur.

## Contexte

Incident du 2026-11-13, partie de 110 slots (session `ee862da7b9cb6fa12b9ebf0bb03f86dc`) : chaque
`/reachable/{slot}` du bridge répondait 500 :

```
reachability generation failed for Terraria: Encountered 1 error(s) in player files. ...
File phil_trrnt_PR.yaml ... is invalid. Exception: No world found to handle game Pokemon Red.
```

La génération avait réussi avec un monde « Pokemon Red », mais le conteneur AP de la session ne l'avait pas :
`/app/Archipelago/Archipelago/lib/worlds` ne contient que `pokemon_rb.apworld` (jeu « Pokemon Red and Blue »),
`/data/apworlds` est vide, `AP_WORLDS_DIR` n'est pas posé. Contournement : partie régénérée sans ce jeu.

Trois défauts dans `archipelago/reachable.py` :

1. **Chemin exact** (`build_exact_multiworld`) : un YAML illisible fait échouer toute la régénération, donc tous
   les slots passent en secours.
2. **Secours** (`build_multiworld`) : il appelle `Generate.main` sur **tout** le dossier de YAML ; le même YAML
   fait encore tout échouer, pour chaque slot.
3. **Choix du YAML** (`_SessionTrackers._build`, l. 989-991) : quand `{nom du slot}.yaml` n'existe pas, il prend le
   premier YAML du dossier, celui d'un autre joueur (ici, le slot 1 Terraria prenait le YAML de Pokemon Red).

Et un défaut de déploiement : les apworlds présents à la génération ne sont pas tous disponibles au suivi.

## Critères d'acceptation

1. **Secours isolé** : le secours d'un slot ne lit que le YAML de ce slot (copié seul dans un dossier temporaire,
   ou lecture directe), jamais tout le dossier. Un YAML illisible d'un autre joueur n'a aucun effet sur lui.
2. **Bon YAML** : le YAML d'un slot est retrouvé par le nom du joueur dans le YAML (`name`), pas seulement par le nom
   de fichier ; sans correspondance, erreur explicite pour ce slot (« YAML du slot introuvable »), jamais le YAML
   d'un autre.
3. **Exact malgré un jeu inconnu** : si la régénération exacte échoue à cause d'un monde absent, les slots des jeux
   connus passent en secours chacun (AC 1) ; le slot du jeu inconnu répond une erreur claire : « Le suivi de ce jeu
   n'est pas disponible : le monde {jeu} n'est pas installé sur le serveur de la partie. » Les autres slots marchent.
4. **Apworlds de la génération au suivi** : l'orchestrateur rend disponibles au conteneur AP de la session les
   apworlds communautaires utilisés à la génération (dossier `apworlds` de la session ou `AP_WORLDS_DIR`). Essai :
   une partie avec un apworld du catalogue absent de l'image donne un suivi exact.
5. **Diagnostic** : le message d'erreur du bridge (`detail`) est affiché sur la fiche de slot et dans le backoffice
   à la place de « HTTP/1.1 500 Internal Server Error returned for ... ».
6. Tests : `archipelago/tests` (YAML illisible d'un autre slot, YAML introuvable, monde absent) ; bridge et API si
   leurs contrats changent ; gates verts. Repo orchestrateur pour l'AC 4.

## Tasks

- [x] Task 1 (AC 1, 2) - `reachable.py` : secours sur le seul YAML du slot, recherche par `name`.
- [x] Task 2 (AC 3) - échec exact : distinguer « monde absent » et basculer slot par slot ; message dédié.
- [ ] Task 3 (AC 4) - orchestrateur : apworlds de la génération copiés dans le volume de la session.
- [ ] Task 4 (AC 5) - API/front : relayer le `detail` du bridge.
- [ ] Task 5 (AC 6) - tests, essai sur une copie de la partie du 2026-11-13 (multidata + YAML + apworlds).

## Dev Notes

- Banc local : voir la mémoire du suivi exact (image `archipelago:*`, `/data/{output,yamls,worlds}`).
- Le data package d'un monde est dans la multidata (le serveur AP le charge), mais le suivi a besoin du code du monde.

## Dev Agent Record

- Hotfix v0.66.2 (2026-11-13), dépôt archipelago : `_slot_yaml` (fichier `{nom}.yaml`, sinon `name:` de premier niveau),
  `build_multiworld` ne donne à Generate qu'un dossier temporaire avec le seul YAML du slot, message « monde non
  installé » sur `No world found to handle game`. Le chemin exact reste tout ou rien : un monde absent fait passer
  tous les slots en secours (seed neuve), chacun isolé. Tests dans `tests/test_session_daemon.py`.
- Site : les slots de l'inscrit en tête de la grille (AC 1 de la 53.9), livré dans le même hotfix.
