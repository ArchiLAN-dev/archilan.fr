# Story 54.6 - Autotracking Archipelago

**Status:** review
**Epic:** 54 - Trackers de jeu, les packs PopTracker sur le site (`_bmad-output/planning-artifacts/epics/epic-54-trackers-poptracker.md`)
**Date:** 2026-11-16
**Branche:** `feature/epic-54-story-6-autotracking-archipelago`
**Dépend de:** 54.5.

## Story

En tant que joueur,
je veux que le tracker se remplisse tout seul avec mes objets reçus et mes checks faits,
afin de ne rien cocher à la main pendant que je joue.

## Contexte

- Interface Archipelago des packs : `doc/AUTOTRACKING.md` de PopTracker, section « Archipelago Interface ».
- Le pack MM : `scripts/autotracking/archipelago.lua` enregistre `onClear(slot_data)` (remise à zéro, prix des
  boutiques depuis `slot_data.shop_prices_ints`, options du YAML), `onItem`, `onLocation`, un handler de data storage
  pour les indices (clé construite avec `Archipelago.TeamNumber` et `PlayerNumber`), et `SetNotify` / `Get`.
- Le shim `Archipelago` existe sans réseau depuis 54.4.
- Le wss vers les rooms est en place (epic 37, plage 35000-35099, Traefik). `connectionUri` est fourni par l'API
  (`parseSession`, `features/events/events-api.ts`).

## Décisions

1. **Client** : `archipelago.js` (version exacte, licence vérifiée), sur le fil principal. Le Worker reçoit des
   messages `ap*` et appelle les handlers du pack. Pas de socket dans le Worker : les erreurs réseau restent visibles
   côté interface.
2. **Connexion** : `Connect` avec `game` = `""`, tags `["Tracker"]`, `items_handling` = `0b111` (tout recevoir), le
   nom de slot et le mot de passe. Le tag `Tracker` ne compte pas comme un client de jeu : pas de conflit avec le jeu
   du joueur, pas de check envoyé.
3. **Correspondance** avec les handlers du pack (spec PopTracker) :
   - `Connected` → `PlayerNumber`, `TeamNumber`, `Seed`, `CheckedLocations`, `MissingLocations`, puis tous les
     handlers Clear avec `slot_data`, puis un handler Location par location déjà faite ;
   - `ReceivedItems` → un handler Item par objet `(index, item_id, item_name, player)`, dans l'ordre des index. Une
     resynchronisation (index 0) rejoue tout après un Clear ;
   - `RoomUpdate.checked_locations` → handlers Location ;
   - `LocationInfo` → handlers Scout ; `Bounced` → handlers Bounced ;
   - `Get` / `SetNotify` → `Retrieved` / `SetReply` ;
   - `Archipelago:GetPlayerAlias`, `GetPlayerGame`, `GetItemName`, `GetLocationName` depuis le data package ;
   - `ClientStatus` présent. `LocationChecks`, `LocationScouts` et `StatusUpdate` → `unsupported_api` (packs
     `apmanual`, hors périmètre).
4. **Lot** : l'ensemble Clear + objets + locations du `Connected` est appliqué en un seul `BulkUpdate`. Une seule
   évaluation des règles à la fin, sinon le chargement d'une partie avancée gèle l'écran.
5. **Indices** : `Highlight` des sections affiché sur la carte et dans le popover (pastille marquée « indice »).
6. **Interface** : bouton « Connecter à Archipelago » (formulaire : adresse, nom de slot, mot de passe), état
   « connecté / reconnexion / erreur », reconnexion automatique avec délai croissant (1 s à 30 s). En autotracking, les
   clics manuels restent possibles (comme PopTracker), mais un Clear les écrase et l'écran le dit.
7. **État local** : en autotracking, l'état manuel n'est pas écrit dans `localStorage`, puisque la room fait foi.
8. **Sécurité** : le mot de passe n'est ni journalisé ni mis dans l'URL. Connexion en `wss://` seulement depuis une
   page https.

## Critères d'acceptation

1. Connecté au slot MM d'une room de test, le tracker affiche les objets reçus et grise les locations déjà faites.
2. Un objet reçu ou un check fait en jeu apparaît dans le tracker en moins de 2 s.
3. Les prix des boutiques (`shopsanity`) s'affichent depuis `slot_data`.
4. Une coupure réseau mène à une reconnexion automatique, et l'état revient sans doublon.
5. Le tracker connecté n'envoie aucun check, et la partie du joueur n'est pas déconnectée.
6. Une partie avec plus de 500 objets reçus se charge sans geler l'interface plus d'une seconde.

## Tests et gates

7. Unitaires : traduction des paquets AP en messages du moteur, ordre et lot, resynchronisation, reconnexion (client
   simulé).
8. Moteur : handlers Clear, Item, Location, Retrieved et SetReply sur le mini-pack (handlers ajoutés aux fixtures).
9. `pnpm gates` vert.
10. Vérification réelle sur une room locale (MM, ou un jeu dont le pack et l'apworld sont disponibles en local),
    notée dans le Dev Agent Record.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 5) - Client `archipelago.js`, connexion avec le tag `Tracker`, messages vers le Worker.
- [x] **Task 2** (AC 1, 3, 6) - Handlers du shim, `BulkUpdate`, data package.
- [x] **Task 3** (AC 2) - Mises à jour en continu, data storage, indices.
- [x] **Task 4** (AC 4) - États de connexion, reconnexion, formulaire.
- [ ] **Task 5** (AC 9, 10) - Gates et essai réel.
  - [x] Gates (typecheck, lint, tests) ; build voir plus bas.
  - [ ] Essai réel sur une room locale : à faire (voir « Vérifications »).

## Dev Agent Record

### Fichiers

- `frontend/package.json` : `archipelago.js` 2.1.0 (MIT), version exacte. `jest.config.mjs` : le paquet est ESM seulement, Jest le transforme.
- `features/tracker/engine/` :
  - `ap-events.ts` : les événements page vers moteur (`connected`, `clear`, `items`, `locations`, `scouted`, `bounced`, `retrieved`, `setReply`, `disconnected`) et ce que le pack demande au serveur (`Get`, `SetNotify`) ;
  - `protocol.ts` : requête `ap` (un lot d'événements) et message spontané `apSend` ;
  - `lua-bootstrap.ts` : décodeur JSON en Lua (entiers gardés entiers, `null` devient `nil`), `AutoTracker:GetConnectionState("AP")` à 3 une fois relié, `PlayerNumber`, `TeamNumber`, `Seed`, `CheckedLocations`, `MissingLocations`, appel des handlers sous `pcall` (un handler qui plante est journalisé, les autres passent), `Get` / `SetNotify` vers le moteur, `GetPlayerAlias`, `GetPlayerGame`, `GetItemName`, `GetLocationName`, `ClientStatus` ;
  - `tracker-engine.ts` : `applyAp(events)` applique un lot avec une seule passe de logique et de watchers à la fin, puis envoie les demandes du pack (`onApSend`) ; les tables de noms restent côté JS ;
  - `worker-host.ts`, `tracker-client.ts` : requête `ap`, relais de `apSend` ;
  - fixture `mini-pack/scripts/archipelago.lua` : handlers Clear, Item, Location, Retrieved et SetReply écrits comme ceux des packs publiés (index courant, prix depuis `slot_data`, indices par clé de data storage).
- `features/tracker/ui/` :
  - `ap-link.ts` : la connexion à la room, sur la page. Traduit les paquets, regroupe une rafale en un lot (un `setTimeout` 0), resynchronise, reconnecte ;
  - `ap-client.ts` : archipelago.js derrière l'interface étroite de `ApLink` (`Tracker`, jeu vide, `items_handling` tout, délai 10 s) ;
  - `room-panel.tsx` : bouton « Connecter à Archipelago », formulaire (adresse, slot, mot de passe), états, avertissement ;
  - `use-tracker-session.ts` : `connectRoom`, `disconnectRoom`, `room`, `roomNotice` ; pas de sauvegarde navigateur pendant que la room est reliée ;
  - `tracker-view.ts` (`HINT_LABELS`, `locationHighlight`), `tracker-map.tsx` (pastille marquée), `location-detail.tsx` (étiquette « indice » sur la section).

### Décisions prises en cours de route

- **Un lot par rafale, pas par paquet** : le `Connected` donne `connected` + `clear` + `locations`, et le `ReceivedItems` qui suit arrive dans la même rafale. Tout part au Worker en une requête `ap`, une seule évaluation des règles. 600 objets d'un coup sur MM : moins d'une seconde (test).
- **Resynchronisation** : un `ReceivedItems` d'index 0 alors que des objets ont déjà été appliqués repart d'un Clear et rejoue tout (rien ne compte double, même pour un pack qui n'ignore pas les index déjà vus). Un trou dans les index envoie un `Sync`. Un index déjà vu est ignoré.
- **Reconnexion** : 1 s, 2 s, 4 s... jusqu'à 30 s, sans fin tant que le joueur ne coupe pas. Une connexion refusée (slot ou mot de passe faux, version) ou une room qui ne répond pas au premier essai s'arrête avec un message, sans boucler. Chaque essai est un nouveau client ; les événements d'un ancien socket sont ignorés (numéro de génération).
- **`Retrieved` clé par clé** : une clé dont la valeur est `null` disparaîtrait d'une table Lua ; un événement par clé garde l'appel du handler.
- **Ce qui écrit dans la room n'existe pas** : `LocationChecks`, `LocationScouts`, `StatusUpdate` restent `unsupported_api`. Le tracker n'envoie que `Get`, `SetNotify` et `Sync`.
- **Clics manuels** : toujours possibles une fois relié. Si un Clear de la room passe derrière, le panneau le dit (« vos clics manuels ont été remplacés »).
- **Sauvegarde** : rien n'est écrit dans le navigateur pendant la connexion, la reconnexion et l'attente. Après une déconnexion volontaire, le suivi manuel reprend à partir de l'état de la room et se sauvegarde de nouveau.
- **Adresse et slot retenus** dans le navigateur (`tracker:room`), jamais le mot de passe. Sur une page https, une adresse sans protocole reçoit `wss://`, et `ws://` est refusé.
- **`Seed`** : `client.room.seedName`.

### Vérifications

- **Gates** : typecheck, lint et tests verts (1819 tests, dont 6 moteur sur le mini-pack, 9 pour la connexion, 2 pour l'écran, 1 pour l'affichage des indices). Tests MM (`TRACKER_MM_PACK_DIR`) verts : 2 nouveaux, la partie remplie depuis une room (Deku, Sonata, location faite grisée, prix de la boutique de bombes lu dans `shop_prices_ints`, clé d'indices `_read_hints_0_1` demandée, aucune erreur hors les 2 connues) et 600 objets en un lot.
- **Build** : compile et génère les pages. Sur 2 des 3 lancements, `next build` s'arrête ensuite sur `SecurityFatalError` : Next signale que la version installée a une faille connue. C'est la montée de Next du hotfix v0.66.3 (#1105, #1106), pas encore mergée.
- **Essai réel : pas fait.** Le banc était prêt (Archipelago 0.6.7, Python 3.12, apworld `mm_recomp` v0.9.5a et son YAML, shopsanity activée) mais la génération, qui exécute l'apworld téléchargé, a été refusée par les permissions de la session. Reste à faire : générer, héberger la room (`MultiServer.py`), relier le tracker au slot, puis vérifier les AC 1 à 5 en jeu ou avec un client de jeu simulé connecté en même temps.

