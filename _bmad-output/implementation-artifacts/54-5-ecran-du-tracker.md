# Story 54.5 - L'écran du tracker

**Status:** review
**Epic:** 54 - Trackers de jeu, les packs PopTracker sur le site (`_bmad-output/planning-artifacts/epics/epic-54-trackers-poptracker.md`)
**Date:** 2026-11-16
**Branche:** `feature/epic-54-story-5-ecran-du-tracker`
**Dépend de:** 54.2 (packs publics), 54.4 (moteur).

## Story

En tant que joueur,
je veux ouvrir le tracker d'un jeu sur le site, cocher mes objets et voir sur les cartes ce qui m'est accessible,
afin de suivre ma partie sans installer PopTracker.

## Contexte

- Le moteur (54.4) renvoie items, cartes, layouts et l'état des sections par messages.
- Racines de layout selon la spec : `tracker_default`, sinon `tracker_horizontal` ou `tracker_vertical`. Le pack MM
  définit `tracker_default`, `tracker_horizontal` et `tracker_broadcast`.
- Packs publics d'un jeu : `GET /api/v1/games/{gameId}/tracker-packs`, archive par
  `GET /api/v1/tracker-pack-versions/{versionId}/archive` (54.2).
- Fiche publique d'un jeu : `features/games/game-detail.tsx` (bloc crédits ajouté en 54.2).

## Décisions

1. **Route** `app/(public)/jeux/[slug]/tracker/page.tsx`, rendu client (le moteur vit dans le navigateur), mise en page
   pleine largeur. Paramètres : `?pack={packId}&variant={uid}` (sinon pack et variante par défaut). Hors index
   (`noindex`) : la page n'a pas de contenu utile au référencement.
2. **Chargement** : archive téléchargée puis mise en cache (Cache Storage, clé = `versionId`, entrées sans usage
   purgées). Indicateur de progression (téléchargement, puis « préparation du pack »).
3. **Rendu des layouts** du pack, un composant par type : `container`, `dock` (positions `top`, `bottom`, `left`,
   `right`, `fill`), `array` (`orientation`), `tabbed`, `group` (en-tête), `item`, `itemgrid`, `layout` (référence),
   `map`, `text`. Les types non gérés (`canvas`, `recentpins`) sont ignorés avec un log. Attributs visuels gérés :
   `item_size`, `item_margin`, `h_alignment`, `v_alignment`, `margin`, `background` ; le reste est ignoré.
4. **Items** : image de l'étape courante, grisée si inactive (comme PopTracker), compteur pour les consumables,
   badge pour `toggle_badged`. Clic gauche = avancer, clic droit = reculer (sans menu contextuel), molette sur les
   consumables. Sur écran tactile : appui = avancer, appui long = reculer.
5. **Cartes** : image de la carte, pastilles aux coordonnées du pack (`location_size`, `location_border_thickness`,
   formes `rect` et `diamond`), couleur selon l'accessibilité. Palette de PopTracker, adaptée aux tokens du site et
   lisible en mode sombre : rouge (inaccessible), jaune (partiel), bleu (inspectable), orange (séquence break), vert
   (accessible), gris (fait). Zoom et déplacement (molette ou pincement, glisser).
6. **Détail d'une location** : popover au clic sur une pastille, avec la liste des sections (nom, image de coffre
   ouvert ou fermé, nombre restant). Un clic fait la section, un clic droit la défait. Un item hébergé (`hosted_item`)
   s'affiche dans sa section.
7. **Légende et réglages** : légende des couleurs, choix de la variante, case « afficher les séquences breaks »,
   case « masquer ce qui est fait ».
8. **État manuel gardé dans le navigateur** (`localStorage`, lecture et écriture dans un try/catch) : clé =
   `tracker:{packageUid}:{packageVersion}:{variantUid}`, puis `:{slotKey}` en 54.7. Bouton « Remettre à zéro ».
   Rien n'est envoyé à l'API dans cette story.
9. **Erreurs du pack** : un panneau repliable « Le pack a signalé des erreurs » liste les `error` et
   `unsupported_api` du moteur, sans bloquer l'écran.
10. **Fiche publique du jeu** : le bloc de 54.2 gagne un bouton « Ouvrir le tracker ».
11. **Crédits** : nom, auteur, licence et lien du pack en pied d'écran.

## Critères d'acceptation

1. Sur un jeu qui a le pack MM, « Ouvrir le tracker » affiche l'inventaire et les cartes du pack, sans connexion
   nécessaire.
2. Cocher Deku, Ocarina, Sonata et une bouteille fait passer Woodfall Temple au vert sur la carte, et le popover liste
   ses sections avec leur état.
3. Faire une section la grise et met à jour la couleur de la location.
4. L'état survit à un rechargement de la page, et « Remettre à zéro » le vide.
5. Les variantes « Items only » et « Maps only » s'affichent.
6. La page est utilisable sur une tablette (pastilles et items assez grands pour le doigt, zoom au pincement).

## Tests et gates

7. Composants : chaque type de layout sur des nœuds de test, item (clics, consumable), pastille (couleur selon le
   niveau), popover, persistance (avec stockage indisponible).
8. Page : chargement avec un moteur simulé, choix de variante, erreurs du pack.
9. `pnpm gates` vert.
10. Vérification réelle sur le pack MM (desktop et tablette ou émulation tactile), captures dans le Dev Agent Record.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Route, chargement de l'archive, cache, progression.
- [x] **Task 2** (AC 1, 5) - Composants de layout et d'item.
- [x] **Task 3** (AC 2, 3, 6) - Carte, pastilles, zoom, popover des sections.
- [x] **Task 4** (AC 4) - Persistance locale, remise à zéro, réglages, légende.
- [x] **Task 5** (AC 1) - Bouton sur la fiche du jeu, crédits, panneau d'erreurs.
- [x] **Task 6** (AC 9, 10) - Gates et vérification réelle.

## Dev Agent Record

### Fichiers

- Route `app/(public)/jeux/[slug]/tracker/page.tsx` (serveur : jeu, packs, `?pack=` et `?variant=`, `noindex`).
- `features/tracker/ui/` :
  - `tracker-screen.tsx` : choix du pack et de la variante, réglages, légende, panneau d'erreurs, crédits, remise à zéro ;
  - `use-tracker-session.ts` : téléchargement, Worker, restauration, actions, sauvegarde ;
  - `tracker-layout.tsx` : un composant par type de widget ;
  - `tracker-item.tsx` : items, clics, molette, appui long ;
  - `tracker-map.tsx` : carte, pastilles, zoom, déplacement, pincement, popover ;
  - `location-detail.tsx` : sections d'une location ;
  - `tracker-context.tsx`, `pack-images.ts` (object URLs lues une fois via le Worker), `layout-attributes.ts`, `tracker-view.ts`, `tracker-storage.ts` ;
  - tests : `tracker-ui-logic`, `tracker-widgets` (jsdom), `tracker-screen` (jsdom, faux Worker), fixture `test-pack.ts`.
- `features/trackers/tracker-packs-api.ts` : `fetchTrackerArchive` (Cache Storage `tracker-packs`, 5 versions gardées, les moins récemment ouvertes partent ; progression par flux).
- `features/trackers/game-tracker-credits.tsx` : bouton « Ouvrir le tracker », `PackCredits` exporté pour le pied de l'écran.
- `app/globals.css` : 6 tokens `--color-tracker-*` (rouge, jaune, bleu, orange, vert, gris), lisibles sur le fond sombre (le site n'a que le thème sombre).
- Moteur : un fichier de layouts peut envelopper ses layouts dans une clé `layouts` (c'est le cas du `tracker.jsonc` de MM) ; ils sont déballés. Fixture `compact/layouts/tracker.json` enveloppée pour le tester, et assertion ajoutée dans `mm-pack.test.ts`.

### Décisions prises en cours de route

- **Une session = une clé React** (`versionId:variante:génération`). Changer de pack, de variante ou remettre à zéro démonte la session et en démarre une neuve : pas de remise à zéro d'état à la main, et le Worker précédent est tué.
- **Remettre à zéro** efface la sauvegarde puis recharge le pack (archive en cache, donc rapide) : c'est le seul moyen sûr de revenir à l'état que le Lua du pack pose au chargement.
- **La sauvegarde compare avec l'état juste après le chargement, pas avec `item_count`.** Le Lua de MM fixe au chargement le nombre de checks de 33 sections compteurs (470 sur 500, etc.). Comparer avec `item_count` les aurait marquées faites au rechargement. On garde par section le nombre restant, par item `active`, `stage`, `count` ; la restauration passe par un `bulk` d'`itemSet`, `sectionClear` et `sectionUnclear`.
- **Archive hors TanStack Query** : le buffer est transféré au Worker (il revient détaché), il ne peut pas vivre dans un cache de requêtes. `fetchTrackerArchive` est appelée par le hook de session, ne lève jamais, renvoie `null`.
- **Dock** : comme le DockPanel de WPF, chaque enfant prend son bord, le dernier remplit ; un enfant sans `dock` va en haut. Sous 1024 px (tablette en portrait, téléphone), les panneaux gauche et droite passent au-dessus ou en dessous de la carte, hauteur bornée et défilante.
- **Tactile** : items à 40 px minimum (`pointer-coarse:`). Les pastilles gardent leur taille dessinée (sinon elles se recouvrent carte dézoomée) mais leur zone de toucher fait au moins 28 px à l'écran (cadre invisible `::before`).
- **Séquences breaks masquées** : affichées comme inaccessibles, comme PopTracker.
- **Widgets non gérés** (`recentpins`, `canvas`...) : rien dessiné, une ligne « Élément d'affichage non géré » dans le panneau d'erreurs.
- **Modificateurs d'image** : `@disabled` (gris), `overlay|chemin` (image par-dessus) ; `@enabled`, `none` et le reste ne changent rien. Un item inactif sans `disabled_img`/`disabled_img_mods` est grisé.

### Vérifications

- **Gates** : `pnpm gates` vert (typecheck, lint, 1801 tests, dont 32 pour les composants et la page, 3 pour le téléchargement et le cache de l'archive, 1 pour le bouton de la fiche ; build). Tests MM (`TRACKER_MM_PACK_DIR`) verts.
- **Vérification réelle** (build de prod, Chrome, API locale, pack MM v0.9.5.3, sans connexion). Captures prises, non versionnées : elles contiennent les visuels du pack, et le dépôt n'a jamais versionné de captures. Ce qu'elles montrent :
  - fiche du jeu : bloc « Tracker disponible » avec « Ouvrir le tracker » ;
  - **AC 1** : inventaire (Items, Masks, Equipment, Dungeon Items, Collectibles) à gauche, onglets Termina / Overworld / Dungeons, carte de Termina avec 102 pastilles. Le panneau d'erreurs liste les 2 erreurs du pack déjà connues en 54.4 (`inverse_rules.lua`, `$small_key_sanity_off`) ;
  - **AC 2** : Deku, Sonata, une bouteille cochés (l'Ocarina est `static` dans ce pack, toujours là) : Woodfall Temple passe d'« Inaccessible » à « En partie accessible » (jaune), pas au vert, car 17 de ses 23 sections demandent encore l'arc ou autre chose, comme dans PopTracker. Le popover liste ses 23 sections (6 en vert : Entrance Chest SF, Ledge, Turtle...; Switch Chest SF en rouge) et l'item hébergé d'Oath to Order ;
  - **AC 3** : Entrance Chest SF faite : barrée, grisée, image `check_32` ; une fois les 6 sections accessibles faites, la pastille passe en rouge (il ne reste que de l'inaccessible) ;
  - **AC 4** : après rechargement, Deku coché, bouteille à 1, Entrance Chest SF faite, aucune section faite par erreur ; « Remettre à zéro » (avec confirmation) vide la sauvegarde et revient à l'état initial ;
  - **AC 5** : « Items only » : 99 items, pas de carte (la zone de droite reste noire : le `tracker_default` du pack pointe vers les onglets de cartes que cette variante ne charge pas, PopTracker affiche le même vide) ; « Maps only » : carte seule, 102 pastilles ;
  - **AC 6** : émulation iPad portrait (820 x 1180, tactile) : inventaire au-dessus, carte en pleine largeur (722 x 706), items à 40 px, zone de toucher des pastilles à 28 px ; pincement simulé à deux pointeurs : zoom de 0,73 à 2,13 autour du centre.
- **Pas vérifié sur une vraie tablette** : appui long et pincement réels au doigt (couverts par les tests jsdom et le pincement simulé).
- **Serveur local** : `next dev` plante toujours (`0xc0000142`), vérification faite sur le build de prod avec `next start` sur `localhost:3000` (le CORS de l'API n'accepte que cette origine).

