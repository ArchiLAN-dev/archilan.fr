# Story 36.7: Fiche utilisateur admin : mise en page et séparation des sections

**Status:** review
**Epic:** 36 - Fiche utilisateur admin
**Date:** 2026-09-29

## Story

En tant qu'administrateur d'ArchiLAN,
je veux une fiche utilisateur aérée, où chaque volet se distingue d'un coup d'œil et où je saute directement à
celui qui m'intéresse,
afin de lire et d'agir sur un compte sans chercher où finit une section et où commence la suivante.

## Contexte

Constat sur la prod (2026-09-29) :

- La fiche est collée à la barre latérale de l'admin : une marge de 16 px seulement, et aucune largeur maximale.
  Sur un grand écran, les cartes s'étirent jusqu'au bord droit et les badges du compte partent à l'autre bout.
- Les huit volets (Identité, Actions, Rôles, Modération, Adhésion, Inscriptions, Jeu, Journal d'activité) ne sont
  séparés que par un titre sur le même fond : rien ne marque la fin d'un volet, la page se lit comme une seule
  liste de cartes.
- Presque chaque valeur est dans sa propre carte bordée (six cartes pour l'identité, une par rôle, une par
  ligne de journal) : le bruit visuel noie les séparations.

Périmètre : présentation de la fiche `/admin/utilisateurs/{id}` seulement. Aucune route, donnée ni règle ne
change, les actions restent les mêmes.

## Critères d'acceptation

1. **Gouttière et largeur** : marge latérale qui grandit avec l'écran (16 px sur mobile, 32 px en tablette,
   40 px en bureau) et largeur maximale `max-w-content`, comme les autres pages de contenu.
2. **En-tête** : lien de retour, initiales en pastille, nom, e-mail et badges (rôle, statut, e-mail non vérifié)
   regroupés ; puis un **sommaire** de liens vers chaque volet (ancres), qui défile horizontalement sur mobile.
3. **Volets séparés** : chaque volet est une section avec un filet en haut et un espacement franc ; sur grand
   écran, le titre (avec son icône) et une phrase d'aide occupent une colonne à gauche, le contenu la colonne de
   droite. Les sections portent un `id` stable (`identite`, `acces`, `moderation`, `adhesion`, `inscriptions`,
   `jeu`, `journal`) et un titre relié par `aria-labelledby`.
4. **Moins de cartes** : l'identité tient dans une seule carte ; les rôles, adhésions, inscriptions, parties
   terminées et entrées du journal sont des listes à séparateurs dans une seule carte ; un état vide est un
   cadre en pointillés. « Actions » et « Rôles » se regroupent dans le volet « Accès et rôles ».
5. Chargement et erreur de chaque volet s'affichent dans leur section, sous le même titre.
6. `pnpm gates` passe.

## Tasks / Subtasks

- [x] **Task 1** (AC 3, 5) - `admin-sheet-section.tsx` : `SheetSection`, sommaire `SheetNav`, liste des volets.
- [x] **Task 2** (AC 1, 2, 4) - `admin-user-detail.tsx` : gouttière, en-tête, sommaire, identité, accès et rôles.
- [x] **Task 3** (AC 3, 4, 5) - Volets modération, adhésion, inscriptions, jeu, journal sur `SheetSection`.
- [x] **Task 4** (AC 6) - Gates et vérification visuelle.

## Dev Agent Record

- **`admin-sheet-section.tsx`** : `SHEET_SECTIONS` (ids et libellés, ordre de la page), `SheetSection` (filet
  `border-t`, titre avec icône et phrase d'aide dans une colonne de 15rem en `lg`, `aria-labelledby`,
  `scroll-mt`), `SheetNav` (sommaire en pastilles, défilement horizontal sans barre sur mobile), `SheetEmpty`
  (cadre en pointillés) et `SHEET_LIST_CLASS` (liste à séparateurs dans une seule carte).
- **Fiche** : conteneur `mx-auto max-w-content` avec gouttière `px-4 md:px-8 lg:px-10` ; en-tête avec pastille
  d'initiales, nom, e-mail, badges (qui passent sous le nom sur mobile) et sommaire ; identité dans une seule
  carte ; « Actions » et « Rôles » réunis dans « Accès et rôles », rôles en liste à séparateurs.
- **Volets** modération, adhésion, inscriptions, jeu et journal sur `SheetSection` (chargement et erreur sous
  le même titre), listes à séparateurs, états vides en pointillés.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `admin-sheet-section.test.tsx` (section ancrée et séparée, sommaire complet et ordonné) | module absent | 2 verts |

Gates : `pnpm gates` vert (593 tests, build) ; lint 0 erreur (10 avertissements préexistants hors périmètre).

### Vérification visuelle

Page complète rendue avec des données d'exemple et le CSS du build, vue dans Chrome en bureau et à 390 px :
sections séparées, gouttière, largeur bornée ; aucun défilement horizontal sur mobile, nom non tronqué.
