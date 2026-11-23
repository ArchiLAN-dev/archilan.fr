# Story 27.14: Les objets reçus portent leur catégorie

**Status:** review
**Epic:** 27 - Configuration des sessions
**Date:** 2026-10-24
**Origine :** revue de la story 27.13, le 2026-10-24 ; Jean a demandé la story le même jour.

## Story

En tant que joueur,
je veux que le toast « objet reçu » de ma page de slot garde la couleur de l'objet (progression, utile, piège),
afin de repérer d'un coup d'œil ce qui compte, sans que la page révèle le contenu des emplacements.

## Contexte

- Les pages de slot d'une partie privée et d'une hebdo affichent un toast par objet reçu, coloré selon ses `flags`
  AP. Elles trouvaient ces `flags` dans le champ `item` des emplacements du push reachable, c'est-à-dire seulement
  quand l'objet avait été placé dans le monde du joueur.
- La story 27.13 retire `item` de la vue joueur : c'était une fuite (on lisait quel objet se trouve où). Les toasts
  joueurs s'affichent donc sans couleur. La page admin, qui reçoit le résultat brut, n'est pas touchée.
- La catégorie d'un objet reçu n'est pas un secret : le joueur l'a en main. AP la transmet dans chaque `NetworkItem`
  (`item`, `location`, `player`, `flags`), mais le bridge la jette : `PlayerState._received_items` ne garde que
  `(item, player, location)` (`core/ap_client.py` `_handle_received_items`, `core/save_parser.py`, `core/state.py`).
- `items_received` est construit par `reachable.py` (image archipelago) : `{id, name, count}`, sans catégorie.

## Critères d'acceptation

1. **Bridge** (dépôt `Archipelago-Bridge`) : `_received_items` garde `flags` en 4e élément, depuis le paquet
   `ReceivedItems` comme depuis la lecture du `.apsave` (`NetworkItem.flags`, 0 si absent). Les éléments envoyés à
   `reachable.py` (`received_items` du payload d'état) le portent aussi.
2. **reachable.py** (image archipelago) : chaque entrée de `items_received` gagne `flags`, l'union (OU binaire) des
   `flags` reçus pour cet objet. Une entrée à 3 éléments reste acceptée (`flags` = 0), pour qu'une image à jour
   tourne avec un ancien bridge.
3. **Front** : les toasts des pages de slot (partie privée, hebdo, admin) lisent la couleur dans
   `items_received[].flags`, avec repli sur l'ancienne lecture des emplacements quand le champ manque (résultat
   brut d'un ancien bridge sur la page admin).
4. **API** : rien à changer ; `ReachabilityForPlayer` garde `items_received` tel quel, et un test le vérifie avec
   `flags`.
5. Aucun retour de `item` dans la vue joueur.

## Tâches

- [x] Bridge : tuple à 4 éléments dans `ap_client.py`, `save_parser.py` (les deux chemins), `state.py`
      (`record_received_item`) ; tests (`test_item_send_tracking.py`, `test_domain.py`).
- [x] archipelago : `reachable.py` (`compute` des deux classes) ; test de parité existant étendu.
- [x] Front : type `items_received[].flags` optionnel dans la garde de type, lecture dans les trois pages ; test.
- [x] API : cas `flags` dans `ReachabilityForPlayerTest`.

## Mise en prod

Dans l'ordre : image archipelago (accepte 3 ou 4 éléments), puis bridge, puis API et front. Dans n'importe quel
autre ordre rien ne casse : les toasts restent simplement sans couleur jusqu'au bout de la chaîne.

## Dev Notes

- Trois dépôts : `Archipelago-Bridge` et l'image archipelago sont des dépôts séparés (mémoire `repo_topology`), le
  front et l'API sont dans le monorepo. Un tag par service, comme d'habitude.
- Vérifier l'ordre réel des champs d'un `NetworkItem` lu dans le `.apsave` avant de lire `flags` par position
  (`item`, `location`, `player`, `flags` côté AP) ; le code lit déjà par attribut quand il existe.

## Dev Agent Record

### Implémentation

- **Bridge** (Archipelago-Bridge #32) :
  - les tuples `(item, player, location)` de `_received_items` ne changent pas, puisque `rest_checks` et
    `rest_passeur` les dépaquettent et les comparent en ensembles ;
  - les flags vivent dans `PlayerState._received_flags`, qui fait le OU par objet. Ils se remplissent depuis le
    paquet `ReceivedItems` (complet comme incrémental), depuis le `.apsave` (`NetworkItem` lu comme
    `item, location, player, flags`) et lors de la fusion d'un état sauvegardé ;
  - le payload envoyé à `reachable.py` reçoit `[item, player, location, flags]` (`received_items_with_flags`).
  - Écart assumé avec la tâche telle qu'écrite (« tuple à 4 éléments ») : le contrat de l'AC 1 est tenu là où il
    compte, dans le payload, sans toucher les autres lecteurs.
- **archipelago** (archilan-archipelago #58) : `_entry_flags` lit le 4e élément, l'attribut `flags` d'un
  `NetworkItem`, ou 0. `items_received[].flags` est le OU par objet.
- **Front** : `newlyReceived` (`reachability/received-items.ts`) remplace le bloc recopié dans les trois pages
  (partie privée, hebdo, admin). Il lit `items_received[].flags` et, à défaut, l'objet placé dans un emplacement
  (résultat brut d'un ancien passage, page admin). Le type `ItemEntry.flags` est optionnel.
- **API** : aucun code, et `ReachabilityForPlayerTest` vérifie que `flags` reste dans la vue joueur. Rien ne revient
  dans `item` côté joueur.

### Mise en prod

Image archipelago, puis bridge, puis site. Dans un autre ordre rien ne casse : les toasts restent neutres jusqu'au
bout de la chaîne.
