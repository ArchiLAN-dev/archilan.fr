# Story 54.11 - Parité avec PopTracker

**Status:** review
**Epic:** 54 - Trackers de jeu, les packs PopTracker sur le site (`_bmad-output/planning-artifacts/epics/epic-54-trackers-poptracker.md`)
**Date:** 2026-11-18
**Branche:** `feature/epic-54-story-11-parite-poptracker`
**Dépend de:** 54.4 (moteur), 54.5 (écran), 54.6 (autotracking), 54.8 (banc d'essai, campagne).

## Story

En tant que joueur,
je veux que le tracker de mon jeu fonctionne sur le site comme dans PopTracker, quel que soit le pack,
afin de ne pas découvrir en pleine partie une carte vide ou une règle fausse.

## Contexte

- Le moteur du site (54.4) a été écrit pour le pack MM, puis complété par la campagne de la 54.8 et au fil des
  pannes (Twilight Princess en 54.9 : contenu d'onglet en liste). On découvre les écarts quand un pack casse.
- PopTracker n'a pas de spécification formelle : sa référence est sa documentation (`doc/PACKS.md`,
  `doc/AUTOTRACKING.md`) et, quand elle est muette, son code source.

## Décisions (Jean, 2026-11-18)

1. **On garde notre moteur** (TypeScript + Lua wasmoon). Pas de compilation de PopTracker en WebAssembly : interface
   de bureau (SDL) à porter, habillage et mobile perdus, licence GPLv3 à gérer, module lourd.
2. **Référence** : PopTracker v0.36.0. Tout ce que sa documentation décrit pour le format de pack, les layouts, les
   items, les locations, les cartes et l'API Lua est inventorié. Un point muet de la doc se tranche dans son code
   source, et la décision est notée dans le Dev Agent Record.
3. **Hors champ** (déjà décidé par l'epic) : autotracking mémoire (SNES, LuaConnector, UAT, `AutoTracker:Read*`),
   packs `apmanual` / `aphintgame`, vue `tracker_broadcast`. Une fonction hors champ appelée par un pack reste
   inoffensive (sans effet, signalée dans le rapport de compatibilité), elle ne fait pas planter le pack.
4. **Méthode** : inventaire d'abord (tableau des écarts dans cette story), puis implémentation par lots, du plus
   courant dans les packs Archipelago au plus rare. Chaque forme ajoutée a son test, chaque lot relève
   `ENGINE_VERSION` pour que les rapports de compatibilité antérieurs soient marqués « à refaire ».
5. **Non-régression** : la campagne de la 54.8 (10 packs) plus Twilight Princess est rejouée à la fin ; aucun pack
   qui se chargeait ne doit régresser.

## Critères d'acceptation

1. Le tableau des écarts couvre chaque élément de la documentation de référence, avec son état (pris en charge,
   partiel, absent, hors champ) et l'endroit du code qui le traite.
2. À la fin de la story, plus aucun élément hors champ exclu n'est « absent » ou « partiel », sauf ceux reportés
   explicitement dans `deferred-work.md` avec la raison.
3. Chaque élément ajouté ou complété a un test (moteur, lecture de layout ou rendu).
4. La campagne rejouée : les 7 packs qui se chargeaient se chargent toujours, Twilight Princess affiche sa carte.
5. `ENGINE_VERSION` relevé.
6. `pnpm gates` vert (et `composer gates` si l'API est touchée).

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Inventaire : tableau des écarts, lots proposés.
- [x] **Task 2** (AC 2, 3, 5) - Implémentation par lots (détail dans le Dev Agent Record).
- [x] **Task 3** (AC 4) - Campagne rejouée.
- [x] **Task 4** (AC 6) - Gates.

## Dev Agent Record

### Inventaire (tâche 1)

Tableau complet en annexe : `54-11-parite-poptracker-ecarts.md` (263 éléments : 153 pris en charge, 40 partiels,
52 absents, 18 hors champ, au départ). Chaque point corrigé a d'abord été vérifié dans le code source de PopTracker
v0.36.0 (`core/jsonitem.{h,cpp}`, `core/luaitem.cpp`, `core/scripthost.cpp`, `core/tracker.cpp`,
`core/location.cpp`, `ui/trackerview.cpp`) : l'inventaire se trompait sur deux points (codes du `composite_toggle`,
référence `@` à une cible inspectable), corrigés d'après le source.

### Lot 1 - Justesse des items et de la logique

- `consumable` sans `max_quantity` : pas de plafond (défaut `min(-1, min - 1)`) ; `decrement` vaut `increment` par
  défaut. Avant, le compteur restait bloqué à 0, au clic comme par l'autotracking.
- `progressive` avec `allow_disabled` et `loop` : clic droit depuis « éteint » vers la dernière étape, allumée.
- `progressive_toggle` : boucle toujours sur ses étapes (PopTracker force `loop`).
- `composite_toggle` : son code compte une fois par partie allumée (0, 1 ou 2), les codes des parties ne sont pas
  repris.
- Items Lua : tout changement (propriété dessinée, `:Set`, clic, handler Archipelago, watcher) relance la logique et
  les watchers, comme `onChange` en amont. Avant, un item Lua modifié par l'autotracking laissait la logique périmée.
- Watchers : `"*"` reçoit les codes de l'item (`", "` entre eux), une watch ajoutée de nouveau sous le même nom passe
  en fin de liste, les codes portés à côté des étapes d'un progressive déclenchent aussi leurs watchers.
- Archipelago : `CheckedLocations` sans doublon, `MissingLocations` tenue à jour, `Archipelago.ClientStatus` (au lieu
  d'un global), `GetConnectionState("AP")` : 0 sans connexion, 3 connecté, 1 après une déconnexion.

Décision : le `toggle` garde le clic gauche qui inverse (PopTracker : gauche allume, droit éteint). Sur téléphone, un
appui doit pouvoir éteindre ; écart volontaire.

### Lot 2 - Affichage

- `img_mods` complets, appliqués de gauche à droite : `@disabled`/`grey`/`gray`/`disable` (0 % de saturation, 67 %
  de luminosité), `dim`, `brightness|x`, `saturation|x|espace`, `greyscale`/`grayscale`, `overlay|chemin|filtres`
  (filtres propres à l'overlay). Rendu en filtres CSS empilés.
- `item_size` : 3 = 32 px, 4 = 48 px, 10 et plus = pixels, le reste garde la taille héritée.
- `map` sans `maps` : toutes les cartes du pack.
- `restrict_visibility_rules` / `force_invisibility_rules` des pins : évaluées par le moteur (`pins` dans l'état,
  seulement pour les pins qui en ont), la carte et les compteurs de la légende les respectent.
- Index de l'écran : un item Lua connu par `CanProvideCodeFunc` seul est trouvé par les codes que nomment les
  layouts ; les codes d'un progressive posés à côté de ses étapes aussi.
- `.Icon` d'un item JSON dessiné jusqu'au prochain changement d'état ; `IconMods` des items Lua ; `:Get`/`:Set` dans
  `.ItemState` ; `.Owner` = table vide ; `IgnoreUserInput` respecté.

### Lot 3 - Fidélité des règles

Combinaison des niveaux réécrite comme `Tracker::resolveRules` : une entrée entièrement remplie l'emporte ; sinon une
entrée en séquence cassée et une entrée inspectable donnent « inspectable » (« séquence cassée » avec
`inspectable_sequence_break`, lu sur la location et hérité par ses sections et enfants). Une référence `@` à une
cible seulement inspectable, hors `{}`, est ignorée (comme en amont) ; un `^$` qui renvoie « inspectable » rend
l'entrée inspectable. Un `@` dans des règles de visibilité demande la visibilité de la cible.

### Performance : Twilight Princess

Le calcul initial de la logique ne se terminait pas (déjà avant cette story). Deux causes :

1. 3 millions de `Tracker:ProviderCountForCode` par passe (parcours de graphe de régions en Lua), chacun traversant
   la frontière Lua - JavaScript. Les comptes sont maintenant gardés côté Lua jusqu'au prochain changement d'état.
2. Un bug du pack : `has("lantern")` appelle `hasAll({"lantern", ...})` qui rappelle `has("lantern")` sans fin
   (`scripts/control.lua`, lignes 5-6). PopTracker finit sur un débordement de pile quasi instantané ; ici il fallait
   9 s par règle touchée. Un garde-fou arrête toute chaîne de plus de 1 000 appels imbriqués (vérifié toutes les
   100 000 instructions, posé à chaque entrée dans le Lua car wasmoon change de thread), avec l'erreur « récursion sans
   fin » ; une règle en échec n'est plus réévaluée avant le prochain changement d'état.

Résultat : environ 22 s par variante au lieu d'un calcul sans fin, 16 erreurs du pack. À signaler à l'auteur du pack.

### Lot 4 - Interface pilotée par le pack

- `settings_popup` : la page « Réglages du pack », à côté des cartes (comme les pages de réglages déjà repérées).
- `Tracker:UiHint` : les hints partent avec l'état suivant ; `ActivateTab` ouvre la carte ou la page dont le chemin
  passe par cet onglet. `Zoom` / `Pan` sont journalisés et ignorés.
- `ScriptHost:AddOnFrameHandler` / `RemoveOnFrameHandler` : une horloge dans le Worker (toutes les 100 ms, tant que le
  pack a des handlers) les appelle avec les secondes écoulées ; une image qui ne change rien n'envoie rien.
- `PopVersion` passe à 0.35.4 (dernière version dont les ajouts sont tous couverts) ; un `min_poptracker_version`
  plus récent est signalé dans le journal. `Tracker:OpenLink` répond comme PopTracker (adresse http(s) valide), sans
  ouvrir de lien.
- `ENGINE_VERSION` : 3.

Le reste (formes de pin 0.36, `max_width`/`max_height`, styles du pack, clic du milieu, `recentpins`, réglages des
badges, `SaveFunc`/`LoadFunc`) est reporté dans `deferred-work.md`, avec la raison.

### Campagne rejouée (après les lots 1 et 2)

Les 10 packs de la 54.8 : mêmes résultats, mêmes erreurs (toutes dues aux packs). Twilight Princess (4 variantes) se
charge.

### Campagne finale (après le lot 4)

Les 20 variantes des 10 packs de la 54.8 : mêmes résultats et mêmes nombres d'erreurs qu'en 54.8 (toutes dues aux
packs). Twilight Princess, qui ne se chargeait pas, se charge dans ses 4 variantes (16 erreurs du pack chacune, la
récursion sans fin). `pnpm gates` vert (1 937 tests).
