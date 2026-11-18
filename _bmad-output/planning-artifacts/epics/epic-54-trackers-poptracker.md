# Epic 54: Trackers de jeu, les packs PopTracker sur le site

**Statut :** proposé le 2026-11-16
**Date :** 2026-11-16
**Origine :** échange avec Jean le 2026-11-16. En jouant à Minish Cap, un joueur doute de ce que son tracker juge
« faisable ». Jean veut « notre équivalent » de PopTracker sur le site, capable de lire les packs existants (exemple :
Majora's Mask, `G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack` v0.9.5.3). Il précise ensuite : « faudrait que les admins
puissent l'ajouter à chaque jeu et qu'on l'importe avec un lien GitHub ».

## Constat (analyse du 2026-11-16)

**PopTracker** (`black-sliver/PopTracker`) est une application C++/SDL sous **GPLv3**. Sa cible WASM existe dans le
`Makefile` mais répond « wasm target not supported at the moment » et `BUILD.md` la dit « outdated and currently
unsupported ». Recompiler PopTracker pour le web n'est donc pas une option : ce serait un canvas sans lien avec notre
interface, et un code GPL à embarquer. **On réimplémente le moteur à partir de la spec publique**
(`doc/PACKS.md`, `doc/AUTOTRACKING.md`), sans recopier de code C++ ni le fichier de définition Lua
(`api/lua/definition/poptracker.lua`, sans licence propre donc GPL).

**Un pack** est un zip : `manifest.json`, des données JSONC, des images, et du **Lua**. Pack MM v0.9.5.3 :

| Élément | Contenu |
|---|---|
| `manifest.json` | `package_uid` `MM_AP`, 3 variantes (`standard`, `itemsonly`, `mapsonly`) avec le flag `ap`, `min_poptracker_version` 0.32.0, `versions_url`. Fichier encodé **avec BOM** |
| `items/*.jsonc` | `toggle` (142), `progressive` (15), `consumable` (18), `progressive_toggle`, `toggle_badged`, `static` |
| `locations/*.jsonc` | 21 fichiers, ~270 Ko : arbre zone > location > sections, `access_rules`, positions sur les cartes, `ref` |
| `maps/maps.json` | 15 cartes |
| `layouts/*.jsonc` | `array`, `dock`, `group`, `itemgrid`, `tabbed`, `map`, `container`, `layout`, `item` ; racines `tracker_default`, `tracker_horizontal`, `tracker_broadcast` |
| `scripts/*.lua` | ~110 Ko : `logic.lua`, `regions.lua`, mapping Archipelago (`location_mapping.lua` 36 Ko) |

Ce sont les scripts Lua qui chargent items, cartes et locations (`Tracker:AddItems(...)` depuis `init.lua`). **Sans
interpréteur Lua, aucun pack sérieux ne s'affiche, même en manuel.**

**Règles d'accès** : les virgules font un ET et chaque entrée du tableau un OU. `$f|a|b` appelle une fonction Lua avec
des arguments, `@zone/section` reprend l'accessibilité d'une autre section, `code:n` teste une quantité. `[...]` marque
une règle optionnelle (séquence break), `{...}` signifie « inspectable mais pas récupérable » et `^$f` renvoie
directement un niveau d'accessibilité.

**API réellement utilisée par MM** : `Tracker:FindObjectForCode` (107 appels), `AddItems`, `AddLocations`, `AddMaps`,
`AddLayouts`, `ProviderCountForCode`, `.BulkUpdate`, `.ActiveVariantUID`, `ScriptHost:LoadScript`, `AddWatchForCode`
(29), les handlers `Archipelago` (Clear, Item, Location, Scout, Bounced, Retrieved, SetReply), `Get`, `SetNotify`,
`PlayerNumber`, `TeamNumber`, `AutoTracker:GetConnectionState`, et les propriétés `.Active`, `.CurrentStage`,
`.AcquiredCount`, `.AvailableChestCount`, `.ChestCount`, `.Highlight`. Le pack compare aussi `PopVersion` en chaîne
(`PopVersion >= "0.32.0"`).

**L'autotracking Archipelago suit le protocole AP** : `Connected` → handlers Clear (avec `slot_data`),
`ReceivedItems` → handlers Item, `checked_locations` → handlers Location, data storage → hints. Le pack fournit ses
propres tables id AP → code (`ITEM_MAPPING`, `LOCATION_MAPPING`) : **le moteur n'a rien à savoir du jeu**.

**Mises à jour** : le manifest peut porter un `versions_url` vers un `versions.json` (`package_version`,
`download_url`, `sha256`, `changelog`). Ce fichier n'est pas toujours tenu : dans le tag v0.9.5.3 de MM, il annonce
0.9.5.2 comme dernière version. La release v0.9.5.3 n'a **aucun asset** : l'auteur dit de prendre « Source code
(zip) », dont tout le contenu est sous un dossier racine `Majoras-Mask-AP-PopTracker-Pack-0.9.5.3/`.

**Côté site, ce qui existe déjà :**
- `Game` (`api/src/GameSelection/Domain/Entity/Game.php`), éditeur admin `/admin/jeux/[gameId]`
  (`features/admin/admin-game-editor`).
- Lecture des releases GitHub pour les apworlds : `ApworldVersionChecker` (`api/src/CatalogSync/Application/Service/`),
  `GITHUB_TOKEN` déjà câblé (`config/services.yaml`), `GithubRateLimitException`, vérification planifiée
  (`CheckApworldUpdatesMessage` dans `api/src/Schedule.php`).
- Stockage MinIO : `S3MinioStorage` (`api/src/Shared/Infrastructure/Adapter/`), URL présignées (`$minioPresignTtl`).
- Infos de connexion d'un slot côté front : `ConnectionData` / `parseSession` (`features/events/events-api.ts` :
  `host`, `port`, `connectionUri`, mot de passe et nom de slot), affichées par `ConnectionFields`. Le wss est en place
  depuis l'epic 37.
- `features/reachability` (suivi exact par sphères) : **reste la référence exacte**. Le tracker de pack apporte la
  logique « humaine » sur carte. Les deux cohabitent.

## Cadrage

- **Les admins rattachent un ou plusieurs packs à un jeu**, en collant un lien GitHub : release, dépôt, ou zip direct.
  Le pack est téléchargé, validé et stocké par l'API, puis servi aux joueurs depuis MinIO.
- **Le Lua ne tourne jamais côté serveur.** Il s'exécute dans le navigateur du joueur, dans un Web Worker, avec
  wasmoon (Lua 5.4 compilé en WASM), dans un bac à sable.
- **Moteur maison en TypeScript** compatible avec la spec PopTracker : chargeur de pack, modèle d'items, évaluateur de
  règles, shim des globales Lua. L'interface est faite de nos composants React, sans recopier l'apparence de
  PopTracker.
- **L'autotracking se connecte directement à la room depuis le navigateur**, en wss, avec le tag `Tracker` et les
  identifiants que le site connaît déjà. Pas de passage par le bridge : le modèle de PopTracker s'applique tel quel, et
  tous les packs `ap` marchent sans adaptation.
- **Accessible depuis chaque partie** (event, hebdo, privée), et en manuel depuis la page publique d'un jeu.

## Principes

- **Nouveau contexte `Trackers`** côté API (`api/src/Trackers/`). Il lit les jeux par une interface de lecture
  déclarée chez lui et implémentée par `GameSelection`, sur le modèle des interfaces de lecture inter-contextes
  existantes.
- **Un pack importé est immuable** : chaque version est un objet MinIO et une ligne en base. On stocke **le zip tel
  que téléchargé** (le `sha256` reste comparable au `versions.json`) et le chemin de sa racine (`rootPath`, le dossier
  qui contient `manifest.json`).
- **Contenu externe = méfiance** : limites de taille, refus des chemins dangereux, aucune exécution côté serveur, mises
  à jour validées par un humain (pas de mise à jour automatique, puisque le pack exécute du code chez les joueurs).
- **Licence** : détectée (API GitHub `license.spdx_id`, sinon fichier `LICENSE`) et affichée. Un pack sans licence
  détectée ne peut être activé qu'après une case cochée par l'admin (« la diffusion de ce pack est autorisée »).
- **Dépendances front** à épingler en version exacte : `wasmoon`, `fflate`, `jsonc-parser`, `archipelago.js`. Toutes
  sous licence MIT, à vérifier au moment de l'ajout.

## Stories

### 54.1 - Importer un pack depuis GitHub

Depuis la fiche admin d'un jeu : coller un lien, téléchargement, validation (taille, chemins, manifest), stockage
MinIO, enregistrement du pack et de sa version.
Détail : `implementation-artifacts/54-1-import-pack-github.md`.

### 54.2 - Les packs d'un jeu

Liste des packs sur la fiche admin du jeu (pack et variante par défaut, activer ou désactiver, licence). Lecture
publique des packs actifs d'un jeu et téléchargement de l'archive.
Détail : `implementation-artifacts/54-2-packs-par-jeu.md`.

### 54.3 - Mises à jour des packs

Vérification planifiée (`versions_url`, sinon releases GitHub), changelog dans l'admin, mise à jour en un clic avec
contrôle du `sha256`, retour à la version précédente.
Détail : `implementation-artifacts/54-3-mises-a-jour-des-packs.md`.

### 54.4 - Le moteur de pack

Web Worker, wasmoon en bac à sable, shim `Tracker`/`ScriptHost`, modèle d'items, locations, évaluateur de règles avec
cache. Le pack MM se charge et calcule l'accessibilité, sans interface.
Détail : `implementation-artifacts/54-4-moteur-de-pack.md`.

### 54.5 - L'écran du tracker

`/jeux/[slug]/tracker` : inventaire rendu depuis les layouts du pack, cartes avec pastilles colorées, détail d'une
location, clics manuels, état gardé dans le navigateur.
Détail : `implementation-artifacts/54-5-ecran-du-tracker.md`.

### 54.6 - Autotracking Archipelago

Connexion wss du navigateur à la room avec le tag `Tracker`, handlers du pack, data storage, indices.
Détail : `implementation-artifacts/54-6-autotracking-archipelago.md`.

### 54.7 - Le tracker depuis mes parties

Bouton « Ouvrir le tracker » sur les slots des parties d'event, hebdos et privées, connexion automatique au bon slot.
Détail : `implementation-artifacts/54-7-tracker-depuis-mes-parties.md`.

### 54.8 - Banc d'essai et compatibilité

Essai d'un pack par l'admin avant activation, rapport d'erreurs Lua et d'API non prise en charge, campagne de tests
sur les packs des jeux les plus joués.
Détail : `implementation-artifacts/54-8-banc-d-essai-et-compatibilite.md`.

### 54.11 - Parité avec PopTracker

Inventaire de tout ce que la documentation de PopTracker (v0.36.0) décrit, comparé au moteur du site, puis
implémentation des écarts par lots, sans attendre qu'un pack casse. Hors champ inchangé (autotracking mémoire,
apmanual, broadcast).
Détail : `implementation-artifacts/54-11-parite-poptracker.md`.

## Ordre et dépendances

```
54.1 → 54.2 → 54.3
54.4 → 54.5 → 54.6 → 54.7
54.2 + 54.5 → 54.8
```

Les deux branches (API et moteur) avancent en parallèle. Pour développer 54.4 sans 54.2, on charge un zip local.

## Hors périmètre (suites possibles)

- Vue `tracker_broadcast` pour OBS (régie, epic 47).
- Packs `apmanual` / `aphintgame` (envoyer des checks ou des scouts depuis le tracker).
- Autotracking mémoire (USB2SNES, LuaConnector, UAT) : impossible depuis un navigateur sans logiciel local.
- Tracker partagé entre plusieurs spectateurs, en temps réel.
- Croiser le tracker avec `reachability` (afficher le verdict exact à côté du verdict du pack).

## Points ouverts

1. **Partie en cours et changement de version** : pas d'épinglage de version par session dans cette epic. Avec
   l'autotracking, l'état est reconstruit à chaque connexion, donc un changement de version reste sans dommage. Seul
   l'état manuel local peut être perdu, et il est rangé par `package_uid` et version (54.5). À revoir si ça gêne en
   pratique.
2. **Lien entre pack et jeu** : manuel, avec une suggestion par le `game_name` du manifest, qui ne correspond pas
   toujours au nom Archipelago du jeu.
