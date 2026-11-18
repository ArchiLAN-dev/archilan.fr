# Story 54.11 - Inventaire des écarts avec PopTracker v0.36.0
Annexe de `54-11-parite-poptracker.md`. Référence : `doc/PACKS.md` et `doc/AUTOTRACKING.md` de PopTracker v0.36.0, code source pour les points muets.

Référence : `PACKS.md` et `AUTOTRACKING.md` de PopTracker v0.36.0, vérifiés sur trois points ambigus dans le source C++
(`src/core/jsonitem.cpp`, `src/core/tracker.cpp`, `src/core/location.cpp`, tag v0.36.0).

Code du site : `frontend/src/features/tracker/` (chemins ci-dessous relatifs à ce dossier). Lecture seule, rien n'a été
modifié.

Légende : Pris en charge = code vu qui traite le point ; Partiel = traité en partie (la note dit ce qui manque) ;
Absent = rien dans le code ; Hors champ = exclu par décision (autotracking mémoire, UAT, apmanual/aphintgame,
vue broadcast).

## Manifest (`manifest.json`)

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Manifest | `name` | Pris en charge | engine/tracker-engine.ts:1026 | |
| Manifest | `game_name` | Pris en charge | engine/tracker-engine.ts:1027 | Lu ; sert surtout à apmanual (hors champ). |
| Manifest | `package_uid` | Pris en charge | engine/tracker-engine.ts:1028 | |
| Manifest | `package_version` | Pris en charge | engine/tracker-engine.ts:1029 | Clé de sauvegarde navigateur (ui/tracker-storage.ts). |
| Manifest | `platform` | Pris en charge | engine/tracker-engine.ts:1030 | Lu, sans effet (seul effet amont = autotracking mémoire). |
| Manifest | `platform_override` | Absent | - | Non lu ; sans effet hors autotracking mémoire. |
| Manifest | `variants` (uid, `display_name`, `flags`) | Pris en charge | engine/tracker-engine.ts:1012 ; ui/tracker-screen.tsx:88 | Sélecteur de variante à l'écran. |
| Manifest | `versions_url` | Absent | - | Mises à jour gérées par l'import GitHub du site, pas par ce champ. |
| Manifest | `min_poptracker_version` | Partiel | engine/tracker-engine.ts:1032 | Lu mais jamais comparé à la version émulée ; aucun avertissement. |
| Manifest | `target_poptracker_version` | Partiel | engine/tracker-engine.ts:1033 | Lu, aucun comportement de compatibilité selon la valeur. |
| Manifest | `author` | Absent | - | Non lu (affichage seulement). |
| Manifest | flag `ap` | Pris en charge | ui/tracker-trial.tsx:47 ; ui/ap-link.ts | La connexion AP est proposée même sans le flag (plus permissif). |
| Manifest | flag `apmanual` | Hors champ | engine/lua-bootstrap.ts:406 | Envoi de checks refusé volontairement. |
| Manifest | flag `aphintgame` | Hors champ | engine/lua-bootstrap.ts:406 | Scouts non envoyés. |
| Manifest | flag `uat` / `uatbridge` | Hors champ | - | |
| Manifest | flags `lorom`, `hirom`, `exlorom`, `exhirom`, `sa-1` | Hors champ | - | SNES mémoire. |
| Manifest | flag `luaconnector` | Hors champ | - | |

## Settings (`settings.json`)

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Settings | Lecture de `settings.json` | Absent | - | Fichier jamais lu. |
| Settings | `smooth_scaling` | Partiel | ui/tracker-context.tsx:68 | Toujours net (pixelated), ce qui est le défaut amont ; `true` ignoré. |
| Settings | `smooth_map_scaling` | Partiel | ui/tracker-map.tsx:221 | Cartes toujours en pixelated alors que le défaut amont est lisse. |
| Settings | `allow_deferred_logic_update` | Absent | - | Optimisation sans effet visible. |
| Settings | `disabled_image_filter` | Absent | - | `@disabled` = toujours gris (brightness-50 + grayscale). |
| Settings | Fusion des surcharges utilisateur | Absent | - | Pas de réglages utilisateur par pack. |

## Chargement, fichiers, Lua général

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Chargement | Point d'entrée `scripts/init.lua` | Pris en charge | engine/tracker-engine.ts:285 | Une erreur après chargement partiel est journalisée, le pack continue. |
| Chargement | Recherche variante puis racine (`[variant]/items/...`) | Pris en charge | engine/pack-files.ts:161 | Plus repli insensible à la casse. |
| Chargement | JSON avec commentaires / virgules finales / BOM | Pris en charge | engine/jsonc.ts:10 | |
| Chargement | Images PNG, GIF, JPEG | Pris en charge | ui/pack-images.ts | Rendu navigateur. |
| Lua | `require` (chemins `scripts/x.lua`, `scripts/x/init.lua`, `x.lua`, `x/init.lua`) | Pris en charge | engine/lua-bootstrap.ts:194 | |
| Lua | `...` = nom du module pour require relatif | Pris en charge | engine/lua-bootstrap.ts:202 | |
| Lua | Global `DEBUG` | Absent | - | Jamais lu ; seulement verbosité. |
| Lua | Global `PopVersion` | Partiel | engine/levels.ts:43 ; engine/lua-bootstrap.ts:569 | Vaut "0.34.0" : un pack qui teste >= 0.35/0.36 croit la fonction absente. |
| Lua | Enum `AccessibilityLevel` | Pris en charge | engine/lua-bootstrap.ts:567 | |
| Lua | Enum `Highlight` | Pris en charge | engine/lua-bootstrap.ts:568 | |

## Global `Tracker`

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Tracker | `.ActiveVariantUID` | Pris en charge | engine/lua-bootstrap.ts:137 | |
| Tracker | `:AddItems` | Pris en charge | engine/lua-bootstrap.ts:138 ; engine/tracker-engine.ts:389 | |
| Tracker | `:AddMaps` | Pris en charge | engine/tracker-engine.ts:406 | |
| Tracker | `:AddLocations` | Pris en charge | engine/tracker-engine.ts:366 | |
| Tracker | `:AddLayouts` | Pris en charge | engine/tracker-engine.ts:373 | Accepte aussi l'enveloppe `layouts`. |
| Tracker | `:AddClasses` | Absent | engine/lua-bootstrap.ts:142 | Ignoré (renvoie true) ; la propriété `class` des layouts n'est donc jamais appliquée. |
| Tracker | `:ProviderCountForCode` | Pris en charge | engine/tracker-engine.ts:704 | Somme des items JSON + items Lua. |
| Tracker | `:FindObjectForCode(code)` (item) | Pris en charge | engine/tracker-engine.ts:428 | Items JSON puis items Lua. |
| Tracker | `:FindObjectForCode("@loc/section")` | Pris en charge | engine/tracker-engine.ts:429 | Suit les `ref`. |
| Tracker | `:FindObjectForCode("@loc")` | Pris en charge | engine/tracker-engine.ts:436 | |
| Tracker | `:UiHint(name, value)` | Absent | engine/lua-bootstrap.ts:157 | Journalisé et ignoré (voir Ui Hints). |
| Tracker | `:OpenLink(url, description)` | Partiel | engine/lua-bootstrap.ts:156 | Sans effet et renvoie nil au lieu d'un booléen. |
| Tracker | `.BulkUpdate` | Pris en charge | engine/tracker-engine.ts:671 | Suspend les watchers ; la logique continue d'être calculée (sans effet visible). |
| Tracker | `.AllowDeferredLogicUpdate` | Pris en charge | engine/lua-bootstrap.ts:162 | Stocké, sans effet (optimisation). |

## Global `ScriptHost`

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| ScriptHost | `:LoadScript` | Pris en charge | engine/lua-bootstrap.ts:218 | |
| ScriptHost | `:AddWatchForCode(name, code, cb)` | Partiel | engine/lua-bootstrap.ts:219, 595 | Le callback reçoit le code de la watch (`"*"` pour la watch globale) et non le code qui a changé ; un item Lua modifié hors clic ne déclenche rien. |
| ScriptHost | `:AddWatchForCode` avec `"*"` | Pris en charge | engine/lua-bootstrap.ts:600 | |
| ScriptHost | `:RemoveWatchForCode` | Pris en charge | engine/lua-bootstrap.ts:224 | |
| ScriptHost | `:CreateLuaItem` | Pris en charge | engine/lua-bootstrap.ts:268 | Voir LuaItem. |
| ScriptHost | `:AddOnFrameHandler` | Absent | engine/lua-bootstrap.ts:367 | Rangé à tort avec la mémoire : no-op + avertissement ; le callback n'est jamais appelé. |
| ScriptHost | `:RemoveOnFrameHandler` | Absent | engine/lua-bootstrap.ts:367 | No-op. |
| ScriptHost | `:AddOnLocationSectionChangedHandler` | Pris en charge | engine/lua-bootstrap.ts:229, 607 | |
| ScriptHost | `:RemoveOnLocationSectionChangedHandler` | Pris en charge | engine/lua-bootstrap.ts:234 | |
| ScriptHost | `:RemoveOnLocationSectionHandler` (ancien nom) | Pris en charge | engine/lua-bootstrap.ts:239 | |
| ScriptHost | `:RunScriptAsync(file, arg, complete, progress)` | Partiel | engine/lua-bootstrap.ts:317, 675 | Exécuté d'un bloc à la fin de la passe ; `progressCallback` ignoré (deferred 54.8). |
| ScriptHost | `:RunStringAsync` | Partiel | engine/lua-bootstrap.ts:322 | Idem. |
| ScriptHost | `:AsyncProgress` | Partiel | engine/lua-bootstrap.ts:326 | No-op (deferred 54.8). |
| ScriptHost | `:AddMemoryWatch` / `:RemoveMemoryWatch` | Hors champ | engine/lua-bootstrap.ts:367 | No-op + avertissement. |
| ScriptHost | `:AddVariableWatch` / `:RemoveVariableWatch` | Hors champ | engine/lua-bootstrap.ts:367 | UAT. |

## Global `AutoTracker` et divers autotracking

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| AutoTracker | `:GetConnectionState("AP")` | Partiel | engine/lua-bootstrap.ts:380 | Renvoie 0 (désactivé) hors connexion au lieu de 1 (déconnecté) ; jamais 2. |
| AutoTracker | `:GetConnectionState("SNES"/"UAT")` | Hors champ | engine/lua-bootstrap.ts:382 | Renvoie 0 (amont : -1 indisponible). |
| AutoTracker | `:ReadU8/16/24/32` | Hors champ | engine/lua-bootstrap.ts:375 | No-op + avertissement. |
| AutoTracker | `:ReadVariable` | Hors champ | engine/lua-bootstrap.ts:375 | |
| AutoTracker | `.SelectedConnectorType` | Hors champ | engine/lua-bootstrap.ts:375 | Renvoie une fonction et non une table. |
| AutoTracker | Type `Segment` (`ReadUInt8`...) | Hors champ | - | |
| AutoTracker | Type `VariableStore` | Hors champ | - | |
| AutoTracker | Fonctions globales `autotracker_started` / `autotracker_stopped` | Hors champ | - | Mémoire seulement en amont. |

## Global `ImageReference`

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| ImageReference | `:FromPackRelativePath` | Pris en charge | engine/lua-bootstrap.ts:558 | |
| ImageReference | `:FromImageReference(original, mod)` | Pris en charge | engine/lua-bootstrap.ts:560 | Chemin et mods portés dans une seule chaîne. Le rendu dépend des mods gérés (voir img_mods). |

## Type `LuaItem` (`ScriptHost:CreateLuaItem`)

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| LuaItem | `.Icon` | Pris en charge | engine/lua-bootstrap.ts:246, 660 | |
| LuaItem | `.IconMods` | Absent | engine/lua-bootstrap.ts:660 | Non transmis à l'écran (seuls Name, Icon, BadgeText, overlay). |
| LuaItem | `.Name` | Pris en charge | engine/lua-bootstrap.ts:660 | |
| LuaItem | `.ItemState` | Pris en charge | engine/lua-bootstrap.ts:275 | Champ libre de la table. |
| LuaItem | `:Get` / `:Set` = store de `.ItemState` | Partiel | engine/lua-bootstrap.ts:257 | Store séparé (`__props`), pas `.ItemState` comme en amont. |
| LuaItem | `.PotentialCodes` | Pris en charge | engine/lua-bootstrap.ts:288 | |
| LuaItem | `.CanProvideCodeFunc` | Partiel | engine/lua-bootstrap.ts:297 | OK pour la logique, mais l'écran indexe les items par `PotentialCodes` : un item Lua sans PotentialCodes est introuvable dans un layout (ui/tracker-view.ts:184). |
| LuaItem | `.ProvidesCodeFunc` | Pris en charge | engine/lua-bootstrap.ts:630 | Nombre ou booléen. |
| LuaItem | `.OnLeftClickFunc` / `.OnRightClickFunc` | Pris en charge | engine/lua-bootstrap.ts:663 | |
| LuaItem | `.OnMiddleClickFunc` | Partiel | engine/lua-bootstrap.ts:666 ; engine/items.ts:18 | Géré côté Lua, mais l'écran n'émet jamais de clic milieu. |
| LuaItem | `.AdvanceToCodeFunc` | Absent | - | Inutilisé en amont aussi. |
| LuaItem | `.SaveFunc` / `.LoadFunc` | Absent | engine/tracker-engine.ts:524 | État perdu au rechargement (deferred 54.8). |
| LuaItem | `.PropertyChangedFunc` | Pris en charge | engine/lua-bootstrap.ts:262 | |
| LuaItem | `:SetOverlay` | Pris en charge | engine/lua-bootstrap.ts:249 | |
| LuaItem | `:SetOverlayColor` / `:SetOverlayBackground` / `:SetOverlayFontSize` / `:SetOverlayAlign` | Absent | engine/lua-bootstrap.ts:253 | No-op. |
| LuaItem | `.Owner` | Absent | engine/lua-bootstrap.ts:272 | Renvoie nil (amont : table vide) ; `item.Owner.x` lève une erreur. |
| LuaItem | `.Type` = "custom" | Pris en charge | engine/lua-bootstrap.ts:270 | |
| LuaItem | `.IgnoreUserInput` | Absent | engine/lua-bootstrap.ts:663 | Les clics appellent les callbacks quand même. |
| LuaItem | `.BadgeText` | Pris en charge | engine/lua-bootstrap.ts:660 | |
| LuaItem | `.BadgeTextColor` | Absent | - | |
| LuaItem | Changement d'état hors clic (handler AP, watcher) | Partiel | engine/tracker-engine.ts:341 | `luaItemChanged` est un no-op : ni watchers, ni invalidation du cache logique ; une règle qui lit l'item peut rester périmée jusqu'au prochain changement d'item JSON. |

## Type `JsonItem` (objet Lua d'un item JSON)

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| JsonItem | `.Active` (lecture/écriture) | Pris en charge | engine/tracker-engine.ts:940, 968 | |
| JsonItem | `.CurrentStage` | Pris en charge | engine/tracker-engine.ts:942, 971 | |
| JsonItem | `.AcquiredCount` | Pris en charge | engine/tracker-engine.ts:944, 974 | Borné par min/max, voir bug `max_quantity` plus bas. |
| JsonItem | `.MinCount` | Pris en charge | engine/tracker-engine.ts:946, 977 | |
| JsonItem | `.MaxCount` | Pris en charge | engine/tracker-engine.ts:948, 983 | |
| JsonItem | `.Increment` / `.Decrement` | Pris en charge | engine/tracker-engine.ts:950, 989 | |
| JsonItem | `:SetOverlay` / `.BadgeText` | Pris en charge | engine/lua-bootstrap.ts:95 ; engine/tracker-engine.ts:995 | Affiché en pastille. |
| JsonItem | `:SetOverlayColor` / `.BadgeTextColor` | Absent | engine/lua-bootstrap.ts:96 | Accepté, ignoré (setItemProperty ne connaît pas la clé). |
| JsonItem | `:SetOverlayBackground` | Absent | engine/lua-bootstrap.ts:97 | Idem. |
| JsonItem | `:SetOverlayFontSize` | Absent | engine/lua-bootstrap.ts:98 | Idem. |
| JsonItem | `:SetOverlayAlign` | Absent | engine/lua-bootstrap.ts:99 | Idem. |
| JsonItem | `.Owner` | Pris en charge | engine/lua-bootstrap.ts:118 | Table vide. |
| JsonItem | `.Type` | Pris en charge | engine/tracker-engine.ts:956 | |
| JsonItem | `.Icon` (surcharge d'image) | Absent | engine/tracker-engine.ts:965 | Écriture ignorée. |
| JsonItem | `.Name` (lecture) | Pris en charge | engine/tracker-engine.ts:954 | Extension. |

## Types `LocationSection` et `Location`

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Section | `.Owner` | Pris en charge | engine/lua-bootstrap.ts:118 | Table vide, comme en amont. |
| Section | `.ChestCount` | Pris en charge | engine/tracker-engine.ts:468 | |
| Section | `.AvailableChestCount` (lecture/écriture) | Pris en charge | engine/tracker-engine.ts:470, 495 | Déclenche les handlers de section. |
| Section | `.AccessibilityLevel` | Pris en charge | engine/tracker-engine.ts:472 | |
| Section | `.FullID` | Pris en charge | engine/tracker-engine.ts:476 | |
| Section | `.Highlight` (lecture/écriture) | Pris en charge | engine/tracker-engine.ts:474, 501 ; ui/tracker-map.tsx:321 | Pastille d'indice sur la carte et dans le détail. |
| Location | `.AccessibilityLevel` | Pris en charge | engine/tracker-engine.ts:462 | |

## Items JSON

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Items | Type `toggle` | Pris en charge | engine/items.ts:140 | |
| Items | Type `progressive` | Partiel | engine/items.ts:202 | Clic droit sur l'état désactivé avec `loop` : l'amont repasse au dernier stage, nous restons éteint. |
| Items | Type `consumable` | Partiel | engine/items.ts:143, 327 | Sans `max_quantity`, l'amont n'a pas de plafond (défaut min(-1, min-1)) ; chez nous min=0 et max=0 bloquent à 0, clic et `AcquiredCount` compris. `decrement` vaut 1 au lieu de `increment` par défaut. |
| Items | Type `progressive_toggle` | Partiel | engine/items.ts:149 | Clic droit au dernier stage : l'amont boucle toujours, nous seulement avec `loop` (que l'amont force à true). |
| Items | Type `static` | Pris en charge | engine/items.ts:138 | |
| Items | Type `composite_toggle` | Partiel | engine/items.ts:121, 161 | Images par état OK ; les codes par état (gauche, droite, les deux) que l'amont dérive de `item_left`/`item_right` ne sont pas fournis, seul le code propre de l'item. |
| Items | Type `toggle_badged` | Pris en charge | engine/items.ts:153 ; ui/tracker-item.tsx:133 | |
| Items | Items Lua personnalisés | Pris en charge | engine/tracker-engine.ts:644 | Voir LuaItem. |
| Items | `name` | Pris en charge | engine/items.ts:300 | |
| Items | `codes` | Pris en charge | engine/items.ts:318 | |
| Items | `img` / `disabled_img` | Pris en charge | engine/items.ts:257 ; ui/tracker-item.tsx:85 | |
| Items | `img_mods` / `disabled_img_mods` (défaut img_mods + @disabled, "none" coupe le défaut) | Pris en charge | ui/tracker-item.tsx:90 | Le rendu dépend des mods gérés (voir img_mods). |
| Items | `stages` | Pris en charge | engine/items.ts:267 | |
| Items | stage `codes` / `inherit_codes` (défaut true) | Pris en charge | engine/items.ts:271 | |
| Items | stage `secondary_codes` | Absent | - | Inutilisé en amont aussi. |
| Items | stage `name` | Pris en charge | engine/items.ts:274 ; ui/tracker-item.tsx:68 | |
| Items | stage `img`, `disabled_img`, `img_mods`, `disabled_img_mods` | Pris en charge | engine/items.ts:274 | |
| Items | `allow_disabled` | Pris en charge | engine/items.ts:353 | |
| Items | `loop` | Pris en charge | engine/items.ts:358 | Mais non forcé à true pour progressive_toggle (voir plus haut). |
| Items | `initial_stage_idx` | Pris en charge | engine/items.ts:362 | |
| Items | `initial_active_state` | Pris en charge | engine/items.ts:355 | |
| Items | `min_quantity` / `max_quantity` | Partiel | engine/items.ts:327 | Défaut de `max_quantity` faux (voir consumable). |
| Items | `increment` / `decrement` | Partiel | engine/items.ts:359 | Défaut de `decrement` = 1 au lieu de `increment`. |
| Items | `initial_quantity` | Pris en charge | engine/items.ts:365 | |
| Items | `overlay_background` | Partiel | engine/items.ts:348 | Lu mais non dessiné (pastille au style fixe). |
| Items | `overlay_font_size` / alias `badge_font_size` | Partiel | engine/items.ts:350 | Lu mais non dessiné. |
| Items | `overlay_align` | Absent | - | |
| Items | `item_left` / `item_right` | Pris en charge | engine/items.ts:330 | |
| Items | `images` (left/right) | Pris en charge | engine/items.ts:343 ; ui/tracker-item.tsx:70 | |
| Items | `base_item` | Pris en charge | engine/items.ts:329 | |
| Items | `codes` au niveau d'un progressive (hors stages) | Partiel | engine/items.ts:325 | Fourni à la logique, mais absent de l'index écran (ui/tracker-view.ts:184) : un layout qui cite ce code ne trouve pas l'item. |
| Items | Ordre : items liés définis avant | Pris en charge | engine/items.ts:305 | |

## Modificateurs d'image (`img_mods`)

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| img_mods | `@disabled` | Pris en charge | ui/pack-images.ts:55 ; ui/tracker-context.tsx:68 | Gris fixe (pas de `disabled_image_filter`). |
| img_mods | `grey` / `gray` / `disable` | Absent | ui/pack-images.ts:55 | Ignorés : l'image reste en couleur. |
| img_mods | `overlay\|path` | Pris en charge | ui/pack-images.ts:55 | |
| img_mods | `overlay\|path\|filtres` | Partiel | ui/pack-images.ts:55 | Tout ce qui suit `overlay\|` devient le chemin : avec des filtres, l'image n'est pas trouvée. |
| img_mods | `brightness\|x` / `dim` | Absent | - | |
| img_mods | `saturation\|x\|colorspace` / `greyscale` / `grayscale` | Absent | - | |
| img_mods | Ordre d'application gauche à droite | Absent | - | Un seul niveau de gris, overlays empilés. |

## Cartes (`maps.json`)

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Maps | `name` / `img` | Pris en charge | engine/tracker-engine.ts:416 | |
| Maps | `location_size` | Pris en charge | ui/tracker-map.tsx:316 | |
| Maps | `location_border_thickness` | Pris en charge | ui/tracker-map.tsx:319 | |
| Maps | `location_border_strength` | Absent | - | 0.36.0. |
| Maps | `location_shape` `rect` / `diamond` | Pris en charge | ui/tracker-map.tsx:320 | |
| Maps | `location_shape` `trapezoid` / `triangle` / `concave_kite` | Absent | ui/tracker-map.tsx:320 | Dessinés en carré. |
| Maps | `location_orientation` | Absent | - | |

## Locations JSON

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Locations | `name` | Pris en charge | engine/locations.ts:152 | |
| Locations | `short_name` | Absent | - | Inutilisé en amont aussi. |
| Locations | `access_rules` | Pris en charge | engine/locations.ts:158 ; engine/tracker-engine.ts:756 | ET avec les parents. |
| Locations | `visibility_rules` | Pris en charge | engine/locations.ts:159 ; engine/tracker-engine.ts:764 | |
| Locations | `chest_unopened_img` / `chest_opened_img` (héritage parent) | Partiel | engine/locations.ts:162, 222 ; ui/location-detail.tsx:67 | Une seule image par section avec un compteur, pas une image par coffre. |
| Locations | `overlay_background` | Partiel | engine/locations.ts:165 | Lu et transmis, jamais dessiné. |
| Locations | `color` | Absent | - | Inutilisé en amont aussi. |
| Locations | `parent` | Pris en charge | engine/locations.ts:94 | Le parent doit être déjà chargé. |
| Locations | `children` | Pris en charge | engine/locations.ts:185 | |
| Locations | `inspectable_sequence_break` | Absent | - | Voir règles : la combinaison glitch + inspect ne donne pas Inspect. |
| Map location | `map`, `x`, `y` | Pris en charge | engine/locations.ts:189 ; ui/tracker-map.tsx:69 | |
| Map location | `size` | Pris en charge | ui/tracker-map.tsx:316 | |
| Map location | `border_thickness` | Pris en charge | ui/tracker-map.tsx:319 | |
| Map location | `border_strength` | Absent | - | |
| Map location | `shape` | Partiel | ui/tracker-map.tsx:320 | rect et diamond seulement. |
| Map location | `orientation` | Absent | - | |
| Map location | `restrict_visibility_rules` | Partiel | engine/locations.ts:202 | Lues mais jamais évaluées : le pin s'affiche quand même. |
| Map location | `force_invisibility_rules` | Partiel | engine/locations.ts:203 | Lues mais jamais évaluées : le pin n'est jamais masqué. |
| Map location | Pin découpé par états des sections | Pris en charge | ui/tracker-view.ts:75, 127 | |
| Section | `name` | Pris en charge | engine/locations.ts:215 | |
| Section | `clear_as_group` | Pris en charge | engine/locations.ts:218 ; engine/tracker-engine.ts:536 | |
| Section | `item_count` (défaut 1, 0 avec hosted_item) | Pris en charge | engine/locations.ts:211 | |
| Section | `hosted_item` (liste de codes) | Pris en charge | engine/locations.ts:210 ; engine/tracker-engine.ts:568 ; ui/location-detail.tsx:101 | |
| Section | `access_rules` / `visibility_rules` | Pris en charge | engine/tracker-engine.ts:774, 784 | |
| Section | `ref` | Pris en charge | engine/locations.ts:140 | Chaînes de ref suivies. |
| Section | `item_size` / `item_width` / `item_height` | Absent | - | 0.32.0 ; taille fixe dans le détail. |
| Section | `chest_unopened_img` / `chest_opened_img` | Pris en charge | engine/locations.ts:222 | |

## Règles d'accès

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Règles | Liste = OU d'entrées, entrée `"a,b"` = ET | Pris en charge | engine/rules.ts:94, 105 | |
| Règles | Code d'item (ProviderCountForCode) | Pris en charge | engine/rules.ts:137 | |
| Règles | `code:n` | Pris en charge | engine/rules.ts:86 | |
| Règles | `$func` (nombre ou booléen) | Pris en charge | engine/rules.ts:132 | |
| Règles | `$func\|a\|b` | Pris en charge | engine/rules.ts:140 | |
| Règles | `$func:n` | Pris en charge | engine/rules.ts:135 | |
| Règles | `^$func` (niveau direct) | Pris en charge | engine/rules.ts:126 | |
| Règles | `@location/section` et `@location` (règles d'accès) | Partiel | engine/rules.ts:122 ; engine/tracker-engine.ts:727 | Une cible au niveau Inspect reste Inspect hors `{}` (amont : None). |
| Règles | `@...` dans des `visibility_rules` | Partiel | engine/tracker-engine.ts:727 | Évalue l'accessibilité de la cible ; l'amont évalue sa visibilité. |
| Règles | `[...]` optionnel (sequence break) | Pris en charge | engine/rules.ts:59, 111 | |
| Règles | `{...}` checkable (Inspect), `{}` vide | Pris en charge | engine/rules.ts:55, 115 | |
| Règles | Combinaison glitch + inspect entre entrées | Partiel | engine/rules.ts:97 | Le max des entrées donne SequenceBreak ; l'amont donne Inspect sauf `inspectable_sequence_break`. |
| Règles | Règle en tableau JSON (virgules permises) | Pris en charge | engine/rules.ts:39 | |
| Règles | Règles en chaîne unique | Pris en charge | engine/rules.ts:34 | |
| Règles | Règles circulaires | Pris en charge | engine/tracker-engine.ts:595, 737 | Passes répétées jusqu'à stabilité (borne 20). |

## Layouts : types

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Layouts | Écran global (story 54.9) | Partiel | ui/tracker-board.tsx:33 ; ui/layout-outline.ts:146 | Choix assumé : cartes à part, blocs d'items en panneau ; la disposition du pack n'est rendue telle quelle qu'en repli (ui/tracker-board.tsx:57). |
| Layouts | `container` | Pris en charge | ui/tracker-layout.tsx:75 | |
| Layouts | `dock` | Pris en charge | ui/tracker-layout.tsx:125 | |
| Layouts | `array` | Pris en charge | ui/tracker-layout.tsx:147 | |
| Layouts | `tabbed` | Pris en charge | ui/tracker-layout.tsx:242 | |
| Layouts | `group` (`header`) | Pris en charge | ui/tracker-layout.tsx:215 | |
| Layouts | `item` | Pris en charge | ui/tracker-layout.tsx:85 | |
| Layouts | `itemgrid` (`rows`) | Pris en charge | ui/tracker-layout.tsx:288 | |
| Layouts | `map` avec `maps` | Pris en charge | ui/tracker-layout.tsx:322 ; ui/layout-outline.ts:87 | |
| Layouts | `map` sans `maps` (= toutes les cartes) | Absent | ui/layout-outline.ts:87 ; ui/tracker-layout.tsx:323 | Aucune carte affichée. |
| Layouts | `layout` (`key`) | Pris en charge | ui/tracker-layout.tsx:314 | |
| Layouts | `recentpins` (`style`, `compact`) | Absent | ui/tracker-layout.tsx:333 | Signalé comme non pris en charge. |
| Layouts | `text` | Pris en charge | ui/tracker-layout.tsx:97 | |
| Layouts | `canvas` (width, height, canvas_left, canvas_top) | Pris en charge | ui/tracker-layout.tsx:164 | |
| Layouts | `content` objet ou tableau | Pris en charge | ui/layout-attributes.ts:17 | |

## Layouts : attributs

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Attributs | `background` | Absent | ui/tracker-layout.tsx:58 | Volontaire (story 54.9 : couleurs du site). |
| Attributs | `header_background` | Absent | - | Couleurs du site. |
| Attributs | `h_alignment` / `v_alignment` | Partiel | ui/layout-attributes.ts:101 | Appliqués comme alignement dans le parent ; défaut `center` des itemgrid non appliqué. |
| Attributs | `dock` | Pris en charge | ui/tracker-layout.tsx:129 | |
| Attributs | `orientation` | Pris en charge | ui/tracker-layout.tsx:148 | Pour `array`. |
| Attributs | `max_height` / `max_width` | Absent | - | |
| Attributs | `margin` (1, 2 ou 4 valeurs) | Partiel | ui/layout-attributes.ts:70 | Valeurs négatives ramenées à 0 ; marges par défaut amont (5, 0 pour items et tableaux internes) absentes. |
| Attributs | `item_margin` | Pris en charge | ui/layout-attributes.ts:61 | |
| Attributs | `item_size` | Partiel | ui/layout-attributes.ts:37 | Pris en pixels : les codes amont 3 (=32) et 4 (=48) donnent des items de 3 ou 4 px. |
| Attributs | `item_width` / `item_height` | Pris en charge | ui/layout-attributes.ts:48 | |
| Attributs | `item_h_alignment` / `item_v_alignment` | Absent | - | Image toujours centrée (object-contain). |
| Attributs | `dropshadow` | Absent | - | Cosmétique. |
| Attributs | `class` (via AddClasses) | Absent | - | Propriétés de classe jamais fusionnées. |
| Attributs | `tabs[].title` | Pris en charge | ui/tracker-layout.tsx:233 | |

## Layouts nommés

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Layouts nommés | `tracker_default` | Pris en charge | ui/tracker-view.ts:197 | |
| Layouts nommés | Repli `tracker_horizontal` / `tracker_vertical` | Pris en charge | ui/tracker-view.ts:198 | |
| Layouts nommés | `settings_popup` | Absent | - | Fenêtre de réglages du pack jamais affichée : ses items ne sont pas accessibles. |
| Layouts nommés | `tracker_capture_item` | Absent | - | Non pris en charge en amont non plus. |
| Layouts nommés | `tracker_broadcast` | Hors champ | - | Vue broadcast. |

## Ui Hints

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Ui Hints | `ActivateTab` | Absent | engine/lua-bootstrap.ts:157 | Les packs qui changent d'onglet selon la zone en jeu ne le font pas. |
| Ui Hints | `Zoom <Map>` / `Zoom <Map>[n]` | Absent | engine/lua-bootstrap.ts:157 | |
| Ui Hints | `Pan <Map>` / `Pan <Map>[n]` | Absent | engine/lua-bootstrap.ts:157 | |

## Archipelago (`AUTOTRACKING.md`)

| Domaine | Élément de référence | État chez nous | Où | Note |
|---|---|---|---|---|
| Archipelago | Activation par flag `ap` + bouton AP | Pris en charge | ui/room-panel.tsx ; ui/ap-link.ts | Connexion avec le tag Tracker. |
| Archipelago | Clear à la connexion (avec slot_data) | Pris en charge | ui/ap-link.ts:192 ; engine/lua-bootstrap.ts:530 | Aussi à un resync (index 0). |
| Archipelago | `.PlayerNumber` | Pris en charge | engine/lua-bootstrap.ts:522 | |
| Archipelago | `.TeamNumber` | Pris en charge | engine/lua-bootstrap.ts:523 | |
| Archipelago | `.CheckedLocations` | Partiel | engine/lua-bootstrap.ts:525, 537 | Rempli par `connected` puis ré-ajouté par l'événement `locations` qui suit : doublons. |
| Archipelago | `.MissingLocations` | Partiel | engine/lua-bootstrap.ts:526 | Jamais mis à jour quand une location est cochée. |
| Archipelago | `.Seed` | Pris en charge | engine/lua-bootstrap.ts:524 | |
| Archipelago | `.ClientStatus` | Partiel | engine/lua-bootstrap.ts:408 | Défini en global `ClientStatus`, pas `Archipelago.ClientStatus` (nil) ; usage surtout apmanual. |
| Archipelago | `:AddClearHandler` | Pris en charge | engine/lua-bootstrap.ts:389, 531 | |
| Archipelago | `:AddItemHandler` (index, id, nom, joueur) | Pris en charge | engine/lua-bootstrap.ts:534 | |
| Archipelago | `:AddLocationHandler` (id, nom) | Pris en charge | engine/lua-bootstrap.ts:540 | |
| Archipelago | `:AddScoutHandler` | Pris en charge | engine/lua-bootstrap.ts:545 | Ne se déclenche que si quelqu'un scoute : le site n'envoie jamais de LocationScouts. |
| Archipelago | `:AddBouncedHandler` | Pris en charge | engine/lua-bootstrap.ts:549 | |
| Archipelago | `:AddRetrievedHandler` | Pris en charge | engine/lua-bootstrap.ts:551 | |
| Archipelago | `:AddSetReplyHandler` | Pris en charge | engine/lua-bootstrap.ts:553 | |
| Archipelago | `:Get(keys)` | Pris en charge | engine/lua-bootstrap.ts:400 ; engine/tracker-engine.ts:324 | Seulement une fois connecté (l'état passe à 3 avant le Clear). |
| Archipelago | `:SetNotify(keys)` | Pris en charge | engine/lua-bootstrap.ts:401 | Idem. |
| Archipelago | `:GetPlayerAlias` / `:GetPlayerGame` | Pris en charge | engine/lua-bootstrap.ts:402 | |
| Archipelago | `:GetItemName` / `:GetLocationName` | Pris en charge | engine/lua-bootstrap.ts:404 ; engine/tracker-engine.ts:346 | |
| Archipelago | Sync sur trou d'index | Pris en charge | ui/ap-link.ts:259 | |
| Archipelago | `:LocationChecks` | Hors champ | engine/lua-bootstrap.ts:406 | apmanual. |
| Archipelago | `:LocationScouts` | Hors champ | engine/lua-bootstrap.ts:406 | apmanual / aphintgame. |
| Archipelago | `:StatusUpdate` | Hors champ | engine/lua-bootstrap.ts:406 | apmanual. |

## Priorités proposées

1. **Lot 1 - Justesse des items et de la logique (bugs silencieux)** : défaut `max_quantity` (consumable bloqué à 0
   sans max, y compris `AcquiredCount` depuis les handlers AP), défaut `decrement`, invalidation et watchers après un
   changement d'item Lua hors clic, argument du callback `AddWatchForCode("*")`, codes de `composite_toggle` par état,
   clics de bord de `progressive` / `progressive_toggle`. Raison : les packs AP comptent clés, fragments et
   triforces en consumables mis à jour par le handler d'item ; un compteur bloqué fausse toute la logique sans message.
2. **Lot 2 - Affichage courant des packs AP** : `img_mods` complets (`overlay\|path\|filtres`, `grey`, `dim`,
   `brightness`, `saturation`, ordre), `item_size` 3/4, `map` sans `maps`, index écran des items Lua sans
   PotentialCodes et des codes de progressive hors stages, `restrict_visibility_rules` / `force_invisibility_rules`,
   `LuaItem.IconMods`, overlays de badge (`overlay_background`, couleur, taille, alignement), `.Icon` des JsonItem.
   Raison : ce sont des mécanismes que beaucoup de packs AP récents utilisent pour griser, superposer et masquer.
3. **Lot 3 - Fidélité des règles et de l'API AP** : combinaison glitch + inspect et `inspectable_sequence_break`,
   `@` dans les visibility_rules (visibilité de la cible), `@` vers une cible Inspect, `MissingLocations` mis à jour,
   doublons de `CheckedLocations`, `Archipelago.ClientStatus`, `GetConnectionState` (1 hors connexion), `PopVersion`
   0.36.0 et vérification de `min_poptracker_version`. Raison : écarts de couleur ou de garde-fou plus rares,
   mais qui produisent des pins faux sur des packs logiques complexes.
4. **Lot 4 - Interface pilotée par le pack** : `settings_popup`, `Tracker:UiHint` (`ActivateTab`, `Zoom`, `Pan`),
   `AddOnFrameHandler`, `AddClasses` + `class`, `settings.json` (lissage des cartes, `disabled_image_filter`),
   `LuaItem.Owner` / `IgnoreUserInput` / clic milieu / overlays, `OpenLink` booléen. Raison : confort et réglages
   de pack, utiles surtout aux packs les plus élaborés ; `settings_popup` en premier car il cache des items.
5. **Lot 5 - Cosmétique et rare** : formes `trapezoid` / `triangle` / `concave_kite`, `border_strength`,
   `orientation`, `max_width` / `max_height`, `item_h_alignment` / `item_v_alignment`, marges par défaut,
   `recentpins`, `item_size` des sections, image par coffre, `SaveFunc`/`LoadFunc`, `AsyncProgress`, `DEBUG`,
   `author`, `platform_override`. Raison : peu d'effet sur la justesse du suivi.
