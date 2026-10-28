# Story 9.59: DeathLink visible en direct sur les slots de la progression

**Status:** review
**Epic:** 9 - Sélection des jeux et YAML
**Date:** 2026-10-27
**Dépôts :** `Archipelago-Bridge` (dossier `bridge/`, dépôt séparé) et `archilan.fr` (frontend seul, l'API relaie sans
changement)
**Origine :** demande de Jean le 2026-10-27 : savoir, sur chaque slot de l'onglet Progression (runs d'event et runs
privés), qui a le DeathLink activé, en direct.

## Story

En tant que joueur ou admin qui suit une partie,
je veux voir sur chaque slot de la progression si le DeathLink est activé,
afin de savoir qui mourra avec moi (et qui me tuera) sans demander à chacun.

## Contexte

### Deux sources, aucune branchée

1. **En direct : les tags du client.** Un client qui joue avec le DeathLink porte le tag `DeathLink`. Le serveur AP
   l'annonce dans le PrintJSON `Join` (`{"type":"Join","team","slot","tags"}`, `MultiServer.py:976`) et, quand le
   client change ses tags en cours de partie (`ConnectUpdate`), dans le PrintJSON `TagsChanged`
   (`{"type":"TagsChanged","team","slot","tags"}`, `MultiServer.py:1998`). Le PrintJSON `Part` ne porte **pas** de
   tags (`MultiServer.py:1006`).
   Le bridge reçoit déjà `Join`/`Part` pour la présence (story 16.18, `bridge/core/ap_client.py:883-889`,
   `_client_joined`/`_client_left` L646-660) mais ne garde que des compteurs par slot, pas les tags, et ignore
   `TagsChanged`.
2. **Le réglage : le spoiler.** Le bridge lit déjà le spoiler des zips de sortie (`_load_spoiler`,
   `ap_client.py:1139`) pour les placements. L'en-tête de chaque joueur liste ses options sous leur `display_name`
   (`BaseClasses.py:1859-1886`) : `Player N: <nom>`, `Game: ...`, puis une ligne par option,
   `f"{display_name + ':':33}{current_option_name}"`. L'option commune `DeathLink` (`Options.py:1518`) s'affiche
   `Death Link:                      Yes`. Un monde peut avoir sa propre option (libellé `DeathLink`, `Deathlink`,
   valeur de choix comme `Receive Only`...). Avec un seul joueur, il n'y a pas de ligne `Player N:`.
   Le spoiler est la vérité post-génération (runs privés, events, hebdos), alors que le YAML stocké côté site
   (`game_slots[].playerYaml`) peut contenir des tirages aléatoires et n'existe pas pour une seed importée.

### Où ça passe aujourd'hui

- Le bridge pousse l'état des slots à Symfony à chaque changement : `_broadcast_state_changed` (L1379) puis
  `_push_state_to_api` (L1385-1409), `POST /api/v1/internal/sessions/{id}/players-push`, charge
  `{"slots": {"<idx>": PlayerState.to_dict()}}` (`bridge/core/domain.py:84-95`). `GET /state`
  (`get_players_state`, L378-392) renvoie le même dict.
- Symfony stocke la charge brute (`SessionPlayersSnapshot`, JSON) et la relaie telle quelle sur Mercure
  `runs/{id}/players` (`PlayersPushController`) ; `GET /api/v1/sessions/{runId}/players` proxifie `/state` avec repli
  sur le snapshot. **Aucun DTO : un nouveau champ par slot passe sans changement côté API.**
- Le frontend affiche les slots dans `frontend/src/components/session/PlayerProgressGrid.tsx` (`SlotCard`,
  L285-395, rangée de badges statut + BK L333-347), type `PlayersSlot` et garde `isPlayersState` dans
  `frontend/src/features/overlay/overlay-api.ts` L61-100. La grille sert sur :
  - l'onglet Progression d'un run privé (`personal-run-detail-page.tsx` L1238) ;
  - l'onglet Progression de la session admin d'event (`admin-session-page.tsx` L1226) ;
  - la page session du joueur d'event (`session-connection-gate.tsx` L248).

## Acceptance Criteria

1. **Tags en direct (bridge).** Le bridge garde, par slot, les tags de chaque client **jouant** connecté
   (`_NON_PLAYING_TAGS` exclus comme pour la présence) :
   - `Join` ajoute une entrée avec ses tags ;
   - `TagsChanged` met à jour l'entrée du client concerné. Avec un seul client sur le slot, c'est lui. Avec plusieurs,
     l'entrée dont les tags ne diffèrent du nouvel ensemble que par `DeathLink`, sinon la plus récente. Un
     `TagsChanged` qui porte un tag d'observateur est ignoré ;
   - `Part` retire une entrée (la plus récente ; `Part` ne dit pas laquelle) et vide le slot quand son compteur de
     présence tombe à 0.
   Avec un seul client par slot, cas courant, le résultat est exact. La règle pour plusieurs clients est documentée
   dans le code.
2. **Réglage (bridge).** `_load_spoiler` lit aussi, dans l'en-tête de chaque joueur, la ligne dont le libellé
   normalisé (minuscules, sans espaces, `_` ni `-`) vaut `deathlink`. Valeur `No`, `Off`, `False`, `Disabled`, `0`,
   `None` = désactivé, toute autre valeur = activé. Pas de ligne = inconnu. Le joueur est retrouvé par son nom
   (`slot_by_alias`, comme les placements) ; seed à un joueur : le slot 1.
3. **Charge.** Chaque slot de `to_dict()` (donc du push Symfony, de Mercure, du snapshot et de `GET /state`) porte :
   - `death_link_live` : `true`/`false` si au moins un client jouant est connecté (vrai si l'un d'eux a le tag),
     `null` sinon ;
   - `death_link_option` : `true`/`false` selon le spoiler, `null` si inconnu (pas de spoiler, option absente).
   Un changement de `death_link_live` (Join, TagsChanged, Part) déclenche le push d'état comme la présence.
4. **Affichage.** Dans `SlotCard`, à côté des badges statut et BK :
   - `death_link_live === true` : badge « DeathLink » (icône crâne lucide `Skull`), net ;
   - `death_link_live === null` et `death_link_option === true` : même badge, atténué, infobulle « Activé dans les
     réglages (joueur hors ligne) » ;
   - `death_link_live === false` et `death_link_option === true` : badge barré ou atténué, infobulle « Activé dans
     les réglages, coupé dans le client » ;
   - sinon rien.
   Libellé accessible (`aria-label`/`title`), pas seulement une icône. Visible partout où la grille est rendue
   (run privé, session admin d'event, page session joueur).
5. **Compatibilité.** `PlayersSlot` gagne les deux champs **optionnels** ; un snapshot ou un bridge ancien sans ces
   champs n'affiche rien et ne casse pas `isPlayersState`. L'overlay, qui réutilise le type, n'est pas modifié.
6. **Tests.**
   - Bridge (`bridge/tests/test_presence.py` ou nouveau `test_death_link.py`) : Join avec/sans tag, TagsChanged
     (activation et coupure en cours de partie), Part, deux clients sur un slot, tracker ignoré, push déclenché ;
     parsing du spoiler (multi-joueurs, un joueur, libellé non standard, valeur de choix, option absente).
   - Frontend (`PlayerProgressGrid` test) : les quatre cas d'affichage, champs absents.
   - Gates verts : `ruff`, `pytest`, `mypy` côté bridge ; `pnpm gates` côté frontend.

## Tasks / Subtasks

- [x] Bridge : tags par client et par slot, `TagsChanged`, `death_link_live` (AC 1, 3)
- [x] Bridge : `death_link_option` depuis le spoiler (AC 2, 3)
- [x] Bridge : tests et gates (AC 6)
- [x] Frontend : `PlayersSlot`, badge dans `SlotCard`, tests, `pnpm gates` (AC 4, 5, 6)
- [ ] Vérification visuelle sur les trois écrans de la grille (pages authentifiées : à faire par Jean sur une
  session en cours après déploiement du bridge)

## Dev Notes

### Points d'attention

- **Le bridge est un dépôt à part** (`ArchiLAN-dev/Archipelago-Bridge`, dossier `bridge/` ignoré par le monorepo) :
  sa propre branche feature et sa propre PR. Lancer ses gates depuis la racine du monorepo (voir `bridge/CLAUDE.md`).
  Le tree `bridge/` est actuellement sur `feature/story-9-56-drop-bridge-slot` : partir de sa branche principale à
  jour, sans emporter ce travail.
- **Ordre de déploiement indifférent** : le frontend tolère l'absence des champs, le bridge ancien n'en envoie pas.
  Le nouveau bridge ne sert qu'aux sessions lancées après son déploiement (image du bridge de session).
- **Redémarrage du bridge** : les `Join` des clients déjà connectés sont perdus (limite déjà présente pour la
  présence, story 16.18). `death_link_live` reste alors `null` jusqu'au prochain `Join`, et l'affichage retombe sur le
  réglage du spoiler. Acceptable, à ne pas corriger ici.
- **Limite connue de la présence, hors périmètre** : `Part` ne porte pas de tags, donc `_client_left` compte comme
  « jouant » le départ d'un tracker, ce qui peut décrémenter le compteur du jeu resté connecté. Ne pas aggraver ; le
  signaler en notes de réalisation si les tests le rendent visible.
- `_death_link_active` (niveau salle, mis à vrai sur un `Bounced`) reste tel quel.
- Ne pas lire le YAML côté API : le spoiler est plus juste (tirages aléatoires résolus) et évite tout travail par
  push.

### Fichiers

- `bridge/core/ap_client.py` : `_handle_print_json` (Join/Part/TagsChanged), état par slot, `_load_spoiler`,
  injection dans l'état poussé.
- `bridge/core/domain.py` : `PlayerState` (deux champs, `to_dict`).
- `bridge/tests/` : nouveaux tests.
- `frontend/src/features/overlay/overlay-api.ts` : `PlayersSlot`.
- `frontend/src/components/session/PlayerProgressGrid.tsx` : badge dans `SlotCard`, plus son test.

### Références

- Story 16.18 (présence Join/Part), 16.17 (plusieurs clients par slot), 9.56 (le bridge sur un slot joueur, tag
  `TextOnly`), 17.21 (snapshot des slots), 9.14 / 11.7 (grille de progression).
- Protocole AP : `runner/docs archipelago/Archipelago-main/docs/network protocol.md` (PrintJSON `Join`,
  `TagsChanged`), `MultiServer.py:976,1006,1998`, `BaseClasses.py:1854-1886` (en-tête du spoiler).

## Dev Agent Record

### Completion Notes List

- Contexte analysé le 2026-10-27 : guide de développement complet.
- Réalisation 2026-10-27.
  - Bridge (PR sur `Archipelago-Bridge`, branche `feature/story-9-59-deathlink-slots`) : `_slot_client_tags`
    (tags des clients jouants par slot), `_client_tags_changed` (PrintJSON `TagsChanged`), `_refresh_death_link`
    (pousse l'état seulement si la valeur change), `death_link_options` (en-tête du spoiler, appelé par
    `_load_spoiler`). `PlayerState` gagne `death_link_live` / `death_link_option`. 12 tests
    (`tests/test_death_link.py`), 227 au total ; ruff et mypy verts.
  - Frontend : `deathLinkState` et `DeathLinkBadge` exportés depuis `PlayerProgressGrid.tsx`, 5 tests.
    `pnpm gates` : typecheck, lint et build verts ; jest vert (1267) avec `NEXT_PUBLIC_APP_URL` par défaut. Le
    `frontend/.env` local pointe sur une IP du réseau et fait échouer 10 tests SEO (robots, sitemap, JSON-LD)
    sans rapport avec la story.
  - API inchangée : la charge par slot passe telle quelle (snapshot, Mercure, `GET /players`).
