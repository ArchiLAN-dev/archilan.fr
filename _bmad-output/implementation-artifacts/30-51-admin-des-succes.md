# Story 30.51: Une admin des succès qu'on manipule

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-08

## Story

En tant qu'admin,
je veux ranger les succès par glisser-déposer, les retrouver vite et lire leurs règles d'un coup d'œil,
afin de gérer un catalogue qui grandit sans cliquer vingt fois sur une flèche.

## Contexte

Retour de Jean (2026-10-08) : la page `/admin/achievements` est une longue liste de cartes à cinq boutons ;
l'ordre se change par des flèches, une place par clic ; « Modifier » remplace toute la page ; les règles sont une
ligne de texte et l'éditeur de groupes (ET / OU / aucune, sous-groupes imbriqués) se lit mal. Maquette validée :
canvas « Admin des succès » (planches Liste, Recherche, Critères, Groupes).

## Critères d'acceptation

1. **Glisser-déposer** de l'ordre par une poignée (souris, doigt, clavier), enregistré au lâcher ; « Déplacer en
   position… » dans le menu « ⋯ » comme alternative. Désactivé pendant une recherche ou un filtre (la liste est
   partielle), avec la mention et un lien « Tout afficher ».
2. **Lignes compactes** : position, image (ou trophée), nom et clé, critère en pastilles, badge de récompense,
   nombre de membres qui l'ont débloqué, interrupteur actif / inactif, menu « ⋯ » (Attribuer à un membre,
   Déplacer en position…). Un succès inactif est estompé.
3. **Recherche** par nom ou clé, filtres actifs / inactifs, par famille de critère, avec récompense.
4. **Panneau latéral** de création et de modification : la liste reste visible ; plein écran sur téléphone.
5. **Critères en pastilles** dans la liste : une couleur et une icône par famille (parties, progression, objectifs
   et événements, quêtes, récaps) ; un sous-groupe est un encadré étiqueté « un de », « tous » ou « aucun de » ;
   au-delà de deux niveaux, « Règle à N niveaux ».
6. **Éditeur de règle en arbre** : rail de couleur par mode de groupe, lien (ET, OU, NI) répété entre les lignes,
   condition en phrase (critère rangé par famille, opérateur, valeur, « entre » à deux valeurs), conditions et
   sous-groupes déplaçables d'un groupe à l'autre par glisser-déposer, « + Condition » et « + Sous-groupe » dans
   chaque groupe ; un encadré « En clair » traduit toute la règle en français.
7. **API** : le tableau de bord renvoie, pour chaque succès, le nombre de membres qui l'ont débloqué.
8. Gates verts ; tests (pastilles et « en clair », déplacements dans l'arbre, filtres, compteur de l'API).

## Hors périmètre

- Le compteur « N membres le débloqueraient » d'une règle en cours d'édition : il faudrait recalculer toutes les
  métriques de tous les membres à chaque modification. Story à part si besoin.
- La suppression d'un succès (pas d'API aujourd'hui ; un succès se désactive).

## Tasks / Subtasks

- [x] **Task 1** (AC 7) - `AdminAchievementService::dashboard()` : `holders` par succès (requête de rareté) ; test.
- [x] **Task 2** (AC 5, 6) - Règles : familles, pastilles, « en clair », éditeur en arbre et déplacements ; tests.
- [x] **Task 3** (AC 1-4) - Liste : `@dnd-kit`, lignes, recherche et filtres, menu, panneau ; tests.
- [x] **Task 4** (AC 8) - Gates.

## Dev Agent Record

- API : `AdminAchievementService::dashboard()` ajoute `holders` (membres listables, la requête de rareté du
  catalogue public, une requête pour tous) ; `AdminAchievementTest::testTheDashboardCountsWhoHoldsEachAchievement`.
- Front :
  - `achievement-rules.ts` : familles de critère, libellé court, valeur (« ≥ 1 000 », « entre … et … »), règle « en
    clair », `moveNode` (déplacement d'une condition ou d'un groupe ; jamais un groupe dans lui-même).
  - `achievement-rule-chips.tsx` : pastilles par famille (icônes lucide), encadrés « tous / un de / aucun de »,
    « Règle à N niveaux » au-delà de trois.
  - `achievement-rule-editor.tsx` : arbre (rail par mode, sélecteur toutes / au moins une / aucune, lien répété),
    critère rangé par famille (`optgroup`), poignées et emplacements de dépôt `@dnd-kit/core`, « En clair ».
  - `admin-achievements-dashboard.tsx` : liste triable `@dnd-kit/sortable` (désactivée sous filtre), recherche
    (casse et accents ignorés), filtres, interrupteur, menu « ⋯ » (Modifier, Attribuer, Déplacer en position…),
    formulaire dans le `Dialog` latéral (taille `wide` ajoutée).
- Tests : `achievement-rules.test.tsx` (familles, « en clair », pastilles, déplacements, filtres).
- Dépendances : `@dnd-kit/core`, `@dnd-kit/sortable`, `@dnd-kit/utilities` (audit vert).

