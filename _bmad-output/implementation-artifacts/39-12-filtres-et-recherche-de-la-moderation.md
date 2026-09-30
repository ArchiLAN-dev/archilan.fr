# Story 39.12: Filtres, recherche et tri de la modération repensés

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-29

## Story

En tant que membre du staff d'ArchiLAN,
je veux une barre de recherche et de filtres claire sur la page de modération, qui montre ce qui est filtré et se
retrouve à l'identique quand je recharge ou partage la page,
afin de trouver vite le signalement ou la contribution à traiter.

## Contexte

Constat de Jean (2026-09-29) : sur `/admin/moderation`, l'onglet Signalements aligne des pastilles de statut puis
quatre listes déroulantes, une case encadrée et une recherche en fin de ligne, sans ordre lisible ; aucun
rappel des filtres actifs, pas de réinitialisation, pas de nombre de résultats ; l'onglet Contributions a sa
propre version ; tout est perdu au rechargement. La story 39.11 a traité les actions (fenêtres, listes à plat),
pas cette barre.

Périmètre : front de `/admin/moderation`. Les paramètres envoyés à l'API ne changent pas.

## Critères d'acceptation

1. **Statut** en contrôle segmenté (En attente / Résolus / Tous ; contributions : En attente / Approuvées /
   Rejetées / Toutes).
2. **Une barre d'outils** : recherche en premier et en large (délai de frappe conservé), bouton « Filtres » avec
   le nombre de filtres actifs, tri à droite. Sur téléphone : recherche en pleine largeur, puis Filtres et Tri.
3. **Panneau de filtres** (popover) : groupes de boutons au lieu de listes déroulantes. Signalements : Cible,
   Contenu, État du commentaire, interrupteur « Non catégorisés ». Contributions : Cible (jeux listés / non
   listés). Lien « Réinitialiser les filtres ».
4. **Filtres actifs** en pastilles retirables sous la barre (la recherche comprise), avec « Réinitialiser ».
5. **Nombre de résultats** au-dessus de la liste ; l'état vide filtré propose d'effacer les filtres.
6. **Adresse de la page** : onglet, statut, filtres, tri et recherche vivent dans l'URL (valeurs par défaut
   omises, valeurs inconnues ignorées) ; recharger, revenir en arrière ou partager le lien redonne la même vue.
   Changer d'onglet repart des filtres par défaut de l'onglet.
7. `pnpm gates` passe.

## Tasks / Subtasks

- [x] **Task 1** (AC 4, 6) - `moderation-filters.ts` : lecture / écriture de l'URL, pastilles, compte, retrait,
      testés.
- [x] **Task 2** (AC 1, 2, 3, 4, 5) - `moderation-toolbar.tsx` partagée (segmenté, recherche, popover de
      filtres, tri, pastilles, compte), testée en rendu statique.
- [x] **Task 3** (AC 6) - Onglets et panneaux branchés sur l'URL (`useSearchParams`, `router.replace`).
- [x] **Task 4** (AC 7) - Gates et vérification visuelle.

## Dev Agent Record

- **`moderation-filters.ts`** (pur) : options et libellés, lecture / écriture de l'URL (`onglet`, `statut`,
  `cible`, `contenu`, `commentaire`, `noncat`, `tri`, `q` ; défauts omis, valeurs inconnues ignorées), pastilles
  des filtres actifs, retrait d'une pastille, réinitialisation (statut et tri conservés).
- **`moderation-toolbar.tsx`** : `ModerationToolbar` (recherche avec délai de frappe et resynchronisée quand
  l'URL change, bouton « Filtres » avec compteur ouvrant un popover Radix, tri en liste stylée, pastilles
  retirables, « Réinitialiser », nombre de résultats), `SegmentedControl` (radiogroup), `FilterGroup`,
  `FilterToggle` (interrupteur existant).
- **Panneaux** : Signalements et Contributions lisent leurs filtres dans l'URL et y écrivent ; le bloc « À
  examiner », indépendant des filtres, passe en tête ; état vide filtré avec « Effacer les filtres ».
- **Tableau de bord** : onglets avec pastille de compte, onglet dans `?onglet=`, `router.replace` sans défilement,
  changer d'onglet repart des défauts ; page enveloppée dans `Suspense` (exigé par `useSearchParams`).
- **Nombre de résultats** : calculé sur la liste affichée. Le `count` de l'API n'est pas un total filtré (les
  contributions renvoient toujours le nombre en attente) et les signalements sont plafonnés à 50 : au-delà,
  « (les 50 premiers) ».

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `moderation-filters.test.ts` | module absent | 8 verts |
| `moderation-toolbar.test.tsx` | écrit avec le composant | 6 verts |

Gates : `pnpm gates` vert (635 tests, build).

### Vérification visuelle

Page rendue avec des données d'exemple et le CSS du build, vue dans Chrome : bureau (statut, recherche large,
Filtres 2, tri, pastilles, « 2 signalements ») et 390 px (recherche pleine largeur, puis Filtres et Tri ; aucun
défilement horizontal). Le contenu du popover n'est pas visible dans ce rendu statique.
