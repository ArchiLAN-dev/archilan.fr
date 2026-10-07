# Story 41.20: Succès des quêtes hebdo

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant que membre,
je veux des succès pour mes quêtes hebdo réussies et mes semaines complètes enchaînées,
afin que la régularité se voie sur mon profil.

## Contexte

La 41.17 a ajouté deux faits de succès, « Quêtes hebdo réussies (total) » (`questsCompleted`) et « Plus longue série
de semaines avec le coffre » (`questChestStreak`), sans succès qui les utilise. Jean (2026-10-06) : « Go les
succès ». Noms façon pop-culture, comme les succès d'événement (mémoire « Achievement naming style »), avec un
sous-titre factuel ; ils restent modifiables dans l'admin des succès.

## Critères d'acceptation

1. Six succès ajoutés au catalogue (actifs, à la suite des existants) :
   - `quest_first` « Un petit pas pour l'homme » : réussir une quête hebdo ;
   - `quest_12` « Les 12 travaux » : réussir 12 quêtes hebdo ;
   - `quest_50` « Sacré Graal ! » : réussir 50 quêtes hebdo ;
   - `chest_first` « Sésame, ouvre-toi » : ouvrir un coffre de la semaine ;
   - `chest_streak_4` « Un jour sans fin » : ouvrir le coffre 4 semaines d'affilée ;
   - `chest_streak_10` « Run, Forrest, run ! » : ouvrir le coffre 10 semaines d'affilée.
2. Migration idempotente (un succès déjà présent sous la même clé n'est pas touché), réversible.
3. Les succès sont accordés par le recalcul horaire existant ; test : les règles accordent les bons succès à partir
   du registre.
4. Gates verts.

## Dev Agent Record

- Définitions dans `App\Community\Domain\Service\QuestAchievementDefinitions`, importée par la migration
  `Version20261006120000` (ON CONFLICT sur la clé, `down()` supprime les six clés) : garder son nom et son espace de
  noms. Jouée sur la base de dev (positions 14 à 19), visibles dans l'admin des succès.
- Fichiers : `api/src/Community/Domain/Service/QuestAchievementDefinitions.php`,
  `api/migrations/Version20261006120000.php`, `api/tests/Functional/QuestAchievementsTest.php`.
- `composer gates` vert (2 781 tests) ; aucun changement front.
