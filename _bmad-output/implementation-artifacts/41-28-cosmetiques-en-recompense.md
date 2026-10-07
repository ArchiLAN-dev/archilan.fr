# Story 41.28: Cosmétiques en récompense des succès et des quêtes

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-07

## Story

En tant que membre,
je veux gagner des cosmétiques exclusifs en débloquant des succès et en faisant des quêtes,
afin que ces récompenses aient une vraie valeur, puisqu'elles ne s'achètent pas.

## Contexte

Deuxième des trois stories (41.27 design des titres, 41.29 collection), retenue par Jean le 2026-10-07. Décisions
annoncées : un cosmétique de quête est surtout pensé pour les quêtes épinglées (une quête tirée chaque semaine qui
en donnerait un le banaliserait) ; un succès retiré à un membre par l'admin lui laisse le cosmétique.

## Critères d'acceptation

1. **Accès « récompense »** pour les cadres, bannières et titres : ni gratuit ni en boutique (une mise en vente est
   refusée), portable seulement une fois gagné.
2. **Origine** : chaque cosmétique possédé garde d'où il vient (boutique, succès, quête) et le nom du succès ou de la
   quête.
3. **Succès** : l'admin choisit un cosmétique optionnel (cadre, bannière, titre ou couleur de pseudo) ; il est donné au
   déblocage (moteur ou attribution manuelle), et aux membres qui ont déjà le succès quand l'admin l'ajoute. Cosmétique
   inconnu : refusé.
4. **Quête** : même choix ; le cosmétique est donné la première fois que la quête est payée, jamais deux fois.
5. **Notification** « Débloqué : Titre « Phil Connors » (succès « Un jour sans fin ») », qui mène à la
   personnalisation du profil. Un cosmétique déjà possédé n'est ni redonné ni annoncé.
6. **Affichage** : l'infobulle du titre sur le profil dit son origine (« Succès « … » », « Quête « … » », « Acheté en
   boutique ») ; les quêtes du portefeuille et l'admin montrent ce qu'elles débloquent.
7. Gates verts ; tests.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-5) - API : accès `reward`, `owned_cosmetic.source` / `source_label`, `CosmeticReward`,
  `CosmeticRewardCatalog`, `CosmeticRewarder`, récompense des succès (moteur, attribution, rattrapage) et des quêtes,
  notification, migration ; tests.
- [x] **Task 2** (AC 1, 5, 6) - Front : accès « Récompense », `CosmeticRewardPicker` (succès, quêtes), notification,
  origine dans l'infobulle, quêtes du portefeuille ; tests.
- [x] **Task 3** (AC 7) - Gates.

## Dev Agent Record

- API : `AvatarFrameAccess::Reward` (portable si possédé, refus « à gagner ») ; `OwnedCosmetic` (`source`,
  `source_label`) ; port `CosmeticOwnershipInterface::grant()` / `origins()` (Wallet) ; `CosmeticReward` (VO),
  `CosmeticRewardCatalog` (existence et libellé par type), `CosmeticRewarder` (donne une fois, notifie
  `cosmetic_unlocked`) ; `AchievementDefinition::rewardWith()` (`cosmetic_type`, `cosmetic_key`), branché dans
  `RecomputeAchievements`, `AdminAchievementGrantService` et `AdminAchievementService` (rattrapage via
  `holdersOf()`) ; `QuestDefinition::unlocks()`, `ManageQuests` (`cosmetic`), `AwardWeeklyQuests` (donné au premier
  paiement) ; vues `QuestAdminQuery` / `MyWeeklyQuests` (`cosmetic`) ; origine du titre dans `CommunityProfileView` ;
  `NameColor::label()`. Migration `Version20261007140000`.
- Front : accès `reward` (catalogues, raisons de verrouillage, libellés admin) ; `cosmetic-reward-picker.tsx` dans
  l'éditeur de succès et de quête ; notification `cosmetic_unlocked` ; infobulle avec l'origine ; « Débloque : … » sur
  les quêtes du portefeuille.
- Tests : `CosmeticRewardTest` (succès, rattrapage, refus, mise en vente refusée, quête une seule fois),
  `cosmetic-reward.test.tsx`. `composer gates` (2 805) et `pnpm gates` (867) verts.
