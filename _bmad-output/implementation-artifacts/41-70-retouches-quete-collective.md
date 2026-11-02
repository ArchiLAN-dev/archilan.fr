# Story 41.70: Retouches de la quête collective

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-02

## Story

En tant qu'admin,
je veux que l'admin des quêtes présente la quête collective comme la 41.69 la définit,
afin de ne pas lire un nombre de quêtes ou un gain maximum faux.

## Contexte

Écarts relevés à la vérification visuelle de la 41.69 (2026-11-02, base locale). Frontend seulement : l'API
exclut déjà la collective du compte de la semaine et de `maxPelles`.

## Critères d'acceptation

1. **En-tête de la semaine** (onglet Semaines, « Cette semaine ») : le nombre de quêtes et le « jusqu'à N par
   membre » ne comptent pas la quête collective, comme l'annonce Discord. Si une collective est servie, l'en-tête
   l'ajoute à part : « 3 quêtes · jusqu'à 150 par membre, coffre compris · collective en plus : +40 ». Le coffre
   n'est compté que s'il y a au moins une quête hors collective.
2. **Fenêtre « Épingler une quête »** : une quête collective porte le badge « Collective », pas « Hors tirage ».
3. **Formulaire** : cocher « Quête collective » préremplit la part minimale avec la suggestion (environ 2 % de la
   cible) si elle est vide, pour ne pas afficher d'erreur avant toute saisie. La suggestion s'accorde au singulier
   (« soit 1 goal »).
4. Gates verts ; tests jest sur l'en-tête, le badge et la suggestion.

## Tâches

- [x] Tâche 1 : en-tête de la semaine sans la collective, ligne à part (AC 1)
- [x] Tâche 2 : badge « Collective » dans la fenêtre d'épinglage (AC 2)
- [x] Tâche 3 : part minimale préremplie, unité au singulier (AC 3)
- [x] Tâche 4 : tests et gates (AC 4)

## Dev Agent Record

- `admin-quest-weeks.tsx` : `weekSummary(week, chestReward)` extrait et testé ; le badge de la fenêtre
  d'épinglage lit `collective` avant `inDraw`.
- `admin-quests-page.tsx` : préremplissage au moment de cocher ; `contributionHint()` exporté pour le singulier.
