# Correctif - Sélection de jeux : réponse trop lourde (mémoire PHP épuisée)

**Status:** review
**Date:** 2026-10-15
**Type:** correctif de prod

## Constat

En prod, `GET /api/v1/runs/{id}/participants/me/game-selection` échoue en 500 :
`Allowed memory size of 134217728 bytes exhausted` dans `JsonResponse`. La réponse portait, pour chacun des ~660 jeux
du catalogue, son YAML par défaut, ses types d'options et la liste complète de ses locations : plusieurs dizaines de Mo.
La sélection de jeux d'une inscription à un événement suit le même schéma pour les jeux configurés de l'événement
(risque à l'ouverture des inscriptions de l'ArchiLAN 4).

## Critères d'acceptation

1. `availableGames` ne porte `defaultYaml`, `optionTypes` et `locationNames` que pour les jeux tenus dans un slot du
   joueur (run perso) ou de l'inscription (événement) ; `null` pour les autres. Le sélecteur n'en a pas besoin, l'éditeur
   YAML d'un slot les lit sur le jeu du slot.
2. La page YAML d'un slot de run perso attend la fin du rechargement de la sélection avant de s'initialiser : les
   données en cache d'avant l'ajout du jeu n'ont pas encore son YAML par défaut.
3. Test fonctionnel (jeu tenu avec ses données, autre jeu sans) et gates verts.

## Dev Agent Record

- `PersonalRunGameSelection::editorData()` et `RegistrationGameSelection` : champs lourds conditionnés au jeu tenu.
- `personal-run-slot-yaml-page.tsx` : hydratation retardée tant que `selectionQuery.isFetching`. La page YAML d'un
  événement relit déjà ses données à chaque montage.
- Test `PersonalRunGameSelectionPayloadTest::testOnlyTheHeldGamesCarryTheirYamlEditorData`.
- Suite possible : ne plus hydrater toutes les entités `Game` (requête légère) pour réduire encore la mémoire.
