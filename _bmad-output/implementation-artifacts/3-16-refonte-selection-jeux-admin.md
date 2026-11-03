# Story 3.16 - Refonte de la sélection des jeux d'un event (admin)

**Epic :** 3 - Sélection de jeux
**Statut :** review
**Date :** 2026-11-03
**Branche :** `feature/epic-3-story-16-refonte-selection-jeux-admin`

## Contexte

La page admin `/admin/evenements/{id}/jeux` affichait les ~800 jeux du catalogue d'un bloc, avec le bouton
« Enregistrer » tout en bas : il fallait faire défiler toute la liste pour enregistrer. Aucun signal de
modification non enregistrée, retour forcé à la liste des events après enregistrement, filtres perdus au défilement,
sélection invisible sans filtrer, lignes hautes, état de l'apworld absent du tableau.

## Critères d'acceptation

1. **Barre d'enregistrement collée en bas d'écran**, toujours visible : nombre de jeux sélectionnés, « Modifications
   non enregistrées » / « Enregistré » / erreur, boutons « Annuler les modifications » (si modifié) et « Enregistrer »
   (actif seulement s'il y a quelque chose à enregistrer).
2. **Garde-fou** : quitter ou recharger la page avec des modifications non enregistrées déclenche l'alerte du
   navigateur.
3. **On reste sur la page** après l'enregistrement.
4. **Catalogue paginé** par 50, la page revenant à 1 à chaque changement de filtre ; barre de recherche et filtres
   collés en haut au défilement (sous la barre mobile de l'admin).
5. **Lignes compactes** : case à cocher à gauche, couverture, nom (slug en clair sur grand écran), plateformes, badges
   « Apworld prêt » / « Apworld non prêt » et « Expérimental ». La case d'en-tête coche la page affichée.
6. **Panneau « Sélection »** à droite sur grand écran (collant), en onglet sur mobile : jeux choisis triés par nom,
   retrait individuel, « Tout retirer ».
7. **Alerte** dans ce panneau quand des jeux sélectionnés n'ont pas d'apworld prêt.
8. **Actions groupées** dans un menu : sélectionner / désélectionner les résultats, ajouter tous les jeux à apworld
   prêt, vider la sélection.

## Tâches

- [x] Réécriture de `features/admin/admin-event-game-selection-page.tsx` (même API `PATCH .../game-selection`).
- [x] Helpers purs exportés : `paginate`, `isDraftDirty` (+ `withAllFiltered` conservé, story 3.14).
- [x] Tests : `admin-event-game-selection-page.test.ts`, `admin-event-game-selection-bulk.test.ts` inchangé.

## Dev Notes

- Front seul, aucune modification d'API.
- Un jeu sélectionné sorti du catalogue depuis garde son nom enregistré dans le panneau.
