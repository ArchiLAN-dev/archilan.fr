# Story 41.61: Le look d'archilan.fr sur les éléments du jeu

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-26
**Dépôt :** `archilan-blind-test` (app seule)
**Origine :** Jean le 2026-10-26, en voyant 41.59 : les éléments du jeu (notifications surtout) doivent avoir le design
d'archilan.fr, pas un style à part.

## Story

En tant que joueur qui connaît archilan.fr,
je veux reconnaître ses notifications, ses badges et ses barres dans le blind test,
afin que l'app fasse partie du site.

## Référence

- Notification : `ItemToast` du site (`frontend/src/features/reachability/item-toast.tsx`), celle des objets reçus en
  run et des overlays OBS. Carte sombre teintée, coins en équerre, trame de lignes, reflet qui passe, tuile d'icône
  lumineuse, libellé en capitales espacées, titre en Space Grotesk, sous-titre discret, gerbe de confettis.
- Badges : pastille arrondie, contour et fond teintés (`rounded-full border px-2 py-0.5 text-xs font-semibold`).
- Barres de progression : fond `border`, remplissage en dégradé `accent` vers `accent-text` avec halo violet
  (`achievement-details.tsx`), vert une fois complète.
- Lignes de classement : cartes `rounded-lg border border-border bg-surface` (`leaderboard-client.tsx`).
- Icônes : lucide (licence ISC), les mêmes que le site.

## Critères d'acceptation

1. **Notifications** au design `ItemToast`, sur le téléphone et l'écran, en trois tons :
   - violet (« Trouvé ») : la trouvaille d'un autre joueur ;
   - ambre (« Premier ! ») : une première trouvaille de la salle, avec confettis ;
   - vert (« Trouvé ! ») : ma propre trouvaille sur le téléphone, avec confettis.
   L'icône dit le type d'œuvre (manette, note, micro, clap, rire). Titre : le joueur ; sous-titre : ce qu'il a trouvé
   (type seulement) et le morceau. Sur l'écran, la même carte en grand.
2. **Badges** au style du site : « Premier ! », les pastilles de l'écran, le compteur d'essais.
3. **Barres** au style du site : temps du morceau (téléphone et écran).
4. **Lignes** au style du site : réponses des cartes de morceau, classement de l'écran (place discrète, trophée
   ambre pour le premier).
5. Un seul fichier de notification partagé (`public/archi-toast.js`) et les styles communs dans `base.html.twig`.
   Le nom du joueur passe toujours par `textContent`.
6. `prefers-reduced-motion` : pas de reflet ni de confettis, un simple fondu.
7. Vérification visuelle sur la maquette (téléphone et écran) ; `composer gates` vert.

## Tâches

- [x] `public/archi-toast.js` et styles communs (`base.html.twig`).
- [x] Téléphone : notifications, badge « Premier ! », barre, lignes.
- [x] Écran : notifications en grand, pastilles, barre, classement.
- [x] Vérification visuelle ; `composer gates`.

## Notes de réalisation (2026-10-26)

- App : commit sur la PR #19 (avec 41.59, pas encore mergée).
- Le téléphone garde deux notifications au plus (trois couvraient la moitié de l'écran) ; l'écran en garde quatre.
- Pastilles de l'écran : badges du site (coche verte et nombre de trouveurs, étincelle et « ? » sinon).
