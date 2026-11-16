# Story 54.5 - L'écran du tracker

**Status:** ready-for-dev
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

- [ ] **Task 1** (AC 1) - Route, chargement de l'archive, cache, progression.
- [ ] **Task 2** (AC 1, 5) - Composants de layout et d'item.
- [ ] **Task 3** (AC 2, 3, 6) - Carte, pastilles, zoom, popover des sections.
- [ ] **Task 4** (AC 4) - Persistance locale, remise à zéro, réglages, légende.
- [ ] **Task 5** (AC 1) - Bouton sur la fiche du jeu, crédits, panneau d'erreurs.
- [ ] **Task 6** (AC 9, 10) - Gates et vérification réelle.
