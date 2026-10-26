# Story 41.59: De l'animation sur le téléphone et sur l'écran de la salle

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-26
**Dépôt :** `archilan-blind-test` (app seule)
**Origine :** Jean le 2026-10-26 :
- le téléphone et l'écran de la salle sont statiques ; il veut une progression, des notifications qui surgissent
  (« tel joueur a trouvé le jeu »), de l'animation qui évoque la musique ;
- un défaut : toucher la première suggestion de l'autocomplétion envoie la proposition, parce que le bouton
  « Proposer » est juste dessous.

## Story

En tant que joueur dans la salle,
je veux voir la partie bouger (ma progression, les trouvailles des autres, le classement qui change),
afin de sentir l'ambiance d'un jeu en direct plutôt qu'un formulaire.

## Critères d'acceptation

### A. Correctif de l'autocomplétion (téléphone et console)

1. Toucher une suggestion la choisit, et rien d'autre : la proposition n'est pas envoyée. Cause : le choix se fait au
   `pointerdown`, la liste disparaît, et le `click` qui suit tombe sur le bouton « Proposer » qui vient de s'activer.
   Correctif : `pointerdown` garde seulement le focus (`preventDefault`), le choix se fait au `click` de la suggestion.
   Le bouton refuse aussi un envoi dans les 300 ms qui suivent un choix, par sécurité.

### B. Fil des trouvailles (données)

2. Une route renvoie les trouvailles récentes de la partie active après un curseur (`?depuis=<id>`) : nom du joueur,
   numéro du morceau, **type** de la source (jeu, musique, chanson…), rang de la trouvaille sur cette source (1er,
   2e…). **Jamais la réponse** : le fil ne doit rien dévoiler du morceau en cours. Lecture seule, accessible au
   téléphone connecté et à l'écran.
3. L'état du morceau en cours expose, sans rien dévoiler : le nombre de sources à trouver, et pour chaque source le
   nombre de joueurs qui l'ont trouvée (sans son type tant que le morceau joue), le temps écoulé et la durée.

### C. Téléphone

4. **Progression du morceau** : une barre de temps qui avance (écoulé sur durée), et « 2 / 4 trouvées » sur ce
   morceau.
5. **Ma trouvaille** : la ligne trouvée entre avec une animation, le compteur pulse, une petite gerbe de confettis
   (CSS, sans bibliothèque) et une vibration courte (`navigator.vibrate`, si le téléphone le permet). Trouvé le
   premier de la salle : « Premier ! » sur la ligne.
6. **Mon erreur** : le champ tremble, le point d'essai se remplit avec une animation.
7. **Les autres** : un toast discret en haut, « Léa a trouvé le jeu » (« … en premier » quand c'est le cas), trois au
   plus à la fois, chacun quelques secondes. Mes propres trouvailles n'y figurent pas (déjà animées).
8. **Classement** : le rang s'anime quand il change (« ↑ 3e »).
9. **Fin du morceau** : la carte du morceau révélé se déplie avec les réponses qui entrent une à une.

### D. Écran de la salle (`/ecran`)

10. **Musique** : pendant qu'un morceau est à l'antenne, un égaliseur animé (barres CSS) et le numéro du morceau en
    grand ; à l'arrêt ou en attente, l'égaliseur se pose.
11. **Progression** : barre de temps du morceau, et une pastille par source à trouver qui s'allume avec le nombre de
    joueurs qui l'ont trouvée (« 3 »), sans dire laquelle.
12. **Notifications** : chaque trouvaille surgit en toast (« Léa a trouvé la musique ! »), mise en avant pour la
    première trouvaille d'une source (« Léa trouve le jeu en premier ! »). File d'attente : au plus quatre à l'écran,
    les suivantes attendent leur tour.
13. **Classement** : les lignes glissent à leur nouvelle place quand l'ordre change (animation FLIP), le score qui
    vient de monter s'illumine.
14. **Révélation** : à la fin du morceau, les réponses apparaissent une à une avec leur type, avant le morceau
    suivant.
15. L'écran interroge l'état toutes les secondes (au lieu de trois) pour que les toasts suivent la salle.

### E. Partout

16. `prefers-reduced-motion` : sans mouvement, les animations deviennent de simples fondus, pas de confettis ni de
    tremblement. Aucune bibliothèque externe, tout en CSS et JS dans les gabarits existants.
17. Tests : la route du fil (curseur, partie active, absence de réponse dans la charge utile), le compteur par source
    de l'état. `composer gates` vert. Vérification visuelle du téléphone et de l'écran.

## Tâches

- [x] `work-picker.js` : choix au `click`, garde de 300 ms (AC 1).
- [x] `GameService` : fil des trouvailles avec curseur, rang de trouvaille ; compteurs par source et temps du
      morceau dans les états joueur et écran.
- [x] `PlayerController` et `RegieController` : route du fil.
- [x] `player/play.html.twig` : barre de temps, animations de trouvaille et d'erreur, toasts, rang animé.
- [x] `regie/screen.html.twig` : refonte (égaliseur, pastilles, toasts en file, classement FLIP, révélation).
- [x] Tests ; `composer gates` ; vérification visuelle.

## Notes

- À livrer de préférence après 41.58 (plusieurs parties), qui range les trouvailles par partie : le fil lit alors la
  partie active. Si 41.59 passe avant, le fil lit l'événement de la partie active, et 41.58 l'ajustera.
- Le correctif de l'autocomplétion (AC 1) peut partir seul et tout de suite, sans attendre le reste.

## Notes de réalisation (2026-10-26)

- App : correctif de l'autocomplétion (AC 1) en PR #16, le reste en PR #19 (empilée sur #17 et #18).
- Le fil n'a pas de route à part : `?depuis=` sur `/api/etat` et `/api/ecran/etat`, pour ne pas doubler les appels.
- Vérification visuelle sur une maquette (fetch simulé), la console et la connexion passant par le site.
