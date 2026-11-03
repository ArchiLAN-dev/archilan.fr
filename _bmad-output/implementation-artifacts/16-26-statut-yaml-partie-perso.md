# Story 16.26 - Statut du YAML et bouton Configurer lisibles dans la sélection de jeux

**Epic :** 16 - Parties perso
**Statut :** review
**Date :** 2026-11-03
**Branche :** `feature/epic-16-story-26-statut-yaml-partie-perso`

## Contexte

Retour de la communauté : dans la sélection de jeux d'une partie perso, beaucoup de joueurs prennent le bouton vert
« YAML configuré » pour une simple info (« c'est prêt ») et ne configurent jamais leurs options. Deux causes :

1. **État faux.** Un slot est créé avec le YAML par défaut de son jeu (`PersonalRunGameSelection`), et le front
   comptait « a un YAML » comme « configuré » : le bouton était vert dès l'ajout du jeu, sans action du joueur.
2. **Action déguisée en badge.** Vert, texte d'état, icône de document : rien ne dit que c'est cliquable.

Le récapitulatif d'inscription d'un event sépare déjà l'état (badge) de l'action (bouton « Configurer »).

## Critères d'acceptation

1. Chaque jeu sauvegardé de la sélection d'une partie perso affiche un **badge d'état** sans bordure ni fond :
   « Options par défaut » (YAML absent ou égal au défaut du jeu), « Personnalisé » (différent du défaut),
   « À configurer » (pas de YAML et pas de défaut).
2. L'accès à l'éditeur est un **bouton « Configurer »** (icône engrenage, bordure, survol accent), libellé accessible
   « Configurer les options de {jeu} ».
3. Sous la liste sauvegardée : « Si tu ne configures pas les options d'un jeu, les valeurs par défaut seront
   utilisées. »
4. Le récapitulatif d'inscription d'un event utilise les **mêmes** composants (état et bouton) : les deux écrans ne
   divergent plus.

## Tâches

- [x] `features/games/slot-yaml-status.tsx` : `resolveSlotYamlStatus`, `SlotYamlStatusBadge`,
  `ConfigureSlotYamlLink`.
- [x] Sélection de jeux d'une partie perso : badge + bouton à la place du lien vert, phrase sur les valeurs par défaut.
- [x] Récap d'inscription d'un event : branché sur les composants partagés (le badge « Par défaut » devient
  « Options par défaut »).
- [x] Tests : `slot-yaml-status.test.tsx`.

## Dev Notes

- Front seul, aucune modification d'API.
- Comparaison texte exacte avec le YAML par défaut, comme le récap d'event : un slot dont le jeu a changé d'apworld
  (38.7) et dont le défaut a bougé apparaît « Personnalisé ».
