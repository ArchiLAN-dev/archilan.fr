# Story 41.34 - Page « Pelles » : quêtes en tête et série en cours

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-14

## Story

En tant que membre qui suit ses quêtes,
je veux trouver mes quêtes de la semaine juste sous mon solde, avec ma série en cours,
afin de voir d'un coup d'oeil ce qu'il me reste à faire et ce que je risque de perdre.

## Contexte

`/compte/portefeuille` s'appelle « Portefeuille » dans le menu de l'espace membre (`account-nav.tsx`) et « Mon
portefeuille » dans le menu utilisateur. La page empile : solde, historique des mouvements, premiers pas, quêtes de la
semaine, historique des quêtes. Les quêtes, qu'on consulte chaque semaine, arrivent donc après un historique paginé.

La série existe déjà côté succès (story 41.17) : semaines ISO consécutives où le coffre de la semaine a été ouvert
(`QuestMetricProvider::longestStreak`). Elle n'est montrée nulle part au membre, ni en cours ni en record.

## Critères d'acceptation

1. **Nom** : l'entrée de menu de l'espace membre devient « Pelles », le menu utilisateur « Mes pelles », le titre de la
   page « Pelles ». L'URL `/compte/portefeuille` ne change pas (liens des notifications, de l'accueil, de la boutique).
2. **Ordre** : solde de pelles, quêtes de la semaine, premiers pas (s'il y en a), historique des mouvements, historique
   des quêtes.
3. **Série en API** : `GET /api/v1/me/quests` renvoie `streak: {current, best, thisWeek}`.
   - `thisWeek` : le coffre de la semaine en cours est ouvert.
   - `current` : semaines consécutives avec coffre, en comptant la semaine en cours si son coffre est ouvert, sinon en
     partant de la semaine précédente (la série reste vivante tant que la semaine n'est pas finie).
   - `best` : la plus longue série, même calcul que le succès.
4. **Série à l'écran** : le panneau « Quêtes de la semaine » affiche la série en cours (« Série : 3 semaines d'affilée »)
   et le record s'il est plus grand. Si la série est en cours mais pas encore prolongée cette semaine : « Ouvre le coffre
   de cette semaine pour la prolonger. » Sans série : « Ouvre le coffre de la semaine pour lancer une série. » Rien
   n'est affiché quand la semaine n'a pas de coffre et qu'aucune série n'est en cours. Une API sans `streak` n'affiche
   rien.
5. Tests (calcul de la série : en cours, prolongée, cassée, passage d'année ; rendu des trois messages ; ordre de la
   page) et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 3) - `MyWeeklyQuests` : `streak` depuis `earnedBy` ; tests fonctionnels.
- [x] **Task 2** (AC 4) - `WeeklyQuests.streak` optionnel + garde de type ; ligne de série dans `WeeklyQuestsView`.
- [x] **Task 3** (AC 1, 2) - Libellés ; `WalletView` scindée en solde et historique, ordre dans `WalletPanel`.
- [x] **Task 4** (AC 5) - Tests et gates.

## Dev Agent Record

- API : `MyWeeklyQuests::streak(chestWeeks, currentWeek)` (statique, pur) depuis `earnedBy` ; `best` réutilise
  `QuestMetricProvider::longestStreak` ; `history()` reçoit `earned` au lieu de le relire. Tests `QuestStreakTest`
  (en cours, prolongée, cassée avec record, passage 2026-W53 vers 2027-W01, vide) et `WeeklyQuestsTest`.
- Front : `WeeklyQuests.streak` optionnel + garde ; `StreakRow` sous le coffre ; `WalletView` prend un emplacement
  `quests` entre le solde et l'historique ; `QuestHistoryPanel` séparé (même requête) en pied de page.
- Libellés : « Pelles » (menu de l'espace membre, titre), « Mes pelles » (menu utilisateur) ; URL inchangée.
