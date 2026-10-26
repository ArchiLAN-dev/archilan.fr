# Story 41.62: Les réponses trop longues défilent seules sur l'écran de la salle

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-26
**Dépôt :** `archilan-blind-test` (app seule)
**Origine :** Jean le 2026-10-26 : un morceau peut avoir plus de réponses que l'écran de la salle n'en affiche. La
liste doit alors défiler toute seule, elle seule, jamais la page (personne ne touche l'écran projeté).

## Critères d'acceptation

1. L'écran de la salle tient dans la fenêtre : la page ne défile pas.
2. La liste des réponses révélées prend la hauteur qui reste sous elle. Si elle déborde, elle défile seule, en
   boucle : pause en haut, descente lente et régulière (vitesse de lecture, pas de saut), pause en bas, retour en haut.
   Un fondu en haut et en bas indique qu'il y a une suite.
3. Le défilement attend que les réponses aient fini d'apparaître une à une (41.59), et repart d'en haut à chaque
   nouveau morceau révélé. Il se recalcule quand la fenêtre change de taille.
4. Le classement suit la même règle (dix lignes peuvent dépasser sur un petit projecteur).
5. Sans débordement, rien ne bouge. Avec `prefers-reduced-motion`, la liste ne défile pas seule : elle garde sa barre
   de défilement.
6. Vérification visuelle sur la maquette (réponses nombreuses, 1600x900 et 1280x720) ; `composer gates` vert.

## Tâches

- [x] `regie/screen.html.twig` : page à la hauteur de la fenêtre, listes en zones défilantes, défilement automatique.
- [x] Vérification visuelle ; `composer gates`.

## Notes de réalisation (2026-10-26)

- App : PR #22. Hauteur des listes calculée à chaque rendu (la scène au-dessus peut grandir), défilement par
  l'API Web Animations, relancé seulement quand le contenu ou la place changent.
- En 1280x720 la zone des réponses est petite (environ 170 px) : la scène prend beaucoup de place sur un petit
  projecteur.
- Dans le même passage, correctif de 41.60 (PR #21) : boutons de pagination du téléphone compacts.
