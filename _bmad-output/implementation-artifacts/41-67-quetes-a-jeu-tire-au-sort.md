# Story 41.67: Quêtes figées à la semaine et jeu tiré au sort

**Status:** ready-for-dev
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-01

## Story

En tant qu'admin,
je veux écrire une fois « Fais 2 goals sur {un jeu} » et que le jeu change chaque semaine,
afin que les quêtes se renouvellent sans que je les réécrive.

## Contexte

Suite de la 41.64. Aujourd'hui une quête ciblée vise un jeu fixe (41.18). Pour tirer un jeu par semaine, il faut
d'abord savoir ce qui a été servi : or `QuestWeekPlanner::served()` renvoie les définitions, et les compteurs, le
paiement, le coffre et Discord relisent les objectifs **en direct** sur la définition (le docblock de
`QuestWeekEntry` parle pourtant d'un instantané). Cette story pose l'instantané dont profitent aussi 41.68 et 41.69.

## Critères d'acceptation

1. **Instantané à la mise en service** : quand une quête est servie (tirage ou épinglage), ses objectifs et sa
   récompense sont copiés dans l'entrée de semaine. Compteurs, paiement, coffre, notification, Discord et
   statistiques lisent l'instantané. Modifier une quête s'applique à ses prochaines semaines ; le titre et la
   description restent lus en direct (corriger une coquille ne demande pas d'attendre). Les entrées d'avant la
   migration, sans instantané, lisent la définition comme aujourd'hui.
2. **Objectif à jeu tiré** : un objectif ciblable peut viser « un jeu tiré au sort » dans un panier choisi par
   l'admin : « jeux du catalogue » (en ligne, non désactivés, joués au moins une fois sur le site) ou « nouveautés
   de N jours » (fenêtre de la 41.66, calculée au début de la semaine servie, même pour un épinglage à l'avance).
   Le panier est stocké dans l'objectif (`pool`).
3. **Tirage** : à la mise en service, le jeu est tiré et inscrit dans l'instantané ; il ne change plus de la
   semaine. Deux objectifs à jeu tiré d'une même quête tirent le même jeu. Le tirage évite le jeu tiré **la
   dernière fois que cette quête a été servie**.
4. **Panier vide** : sans jeu éligible, le tirage passe à une autre quête ; un épinglage est refusé avec un message.
   Un objectif non résolu ne doit jamais atteindre les compteurs (aujourd'hui une cible inconnue compte 0 en
   silence) : assertion et test.
5. **Affichage** : le membre voit le jeu tiré (« 2 goals sur Hollow Knight ») avec un lien vers sa fiche ; l'admin
   voit le modèle (« un jeu tiré parmi les nouveautés de 30 jours ») dans l'onglet Types et le jeu tiré dans
   l'onglet Semaines.
6. **Stats** : une même quête servie avec des jeux différents reste une seule quête dans les stats (41.16).
7. Gates verts ; tests (instantané contre une modification en cours de semaine, entrée sans instantané, tirage
   figé, pas de répétition, panier vide, épinglage à l'avance) et des écrans.

## Notes techniques

- Migration : `quest_week_entry.objectives` (JSON nullable) et `reward` (int nullable).
- `served()` renvoie les entrées résolues (définition + instantané) ; à adapter : `isAccomplishedWith`,
  `objectivesOf`, `accomplishers` (`AwardWeeklyQuests`), `maxPelles` de la notification, `MyWeeklyQuests`,
  `QuestAdminQuery`, `AnnounceQuestsOnDiscord::scopeNames`.
- `ManageQuests::objectives` accepte la cible « tirée » avec son panier ; `scope()` refuse une cible non résolue.
- Le panier se lit par le port `QuestGameCatalog` de la 41.66 (jeux éligibles, avec slug pour le lien) ;
  générateur aléatoire injecté comme `QuestDraw`.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1) - Migration, instantané à la mise en service, lecteurs adaptés ; tests de non-régression.
- [ ] **Task 2** (AC 2, 3, 4) - Panier, tirage, garde-fou ; tests.
- [ ] **Task 3** (AC 5, 6) - Formulaire, affichages membre, admin et Discord ; tests jest.
- [ ] **Task 4** (AC 7) - Gates, vérification dans l'app sur deux semaines.
