# Story 30.52: Les collections de succès

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-08

## Story

En tant que membre,
je veux voir les succès rangés par thème, avec ma progression dans chaque série,
afin d'avoir envie de compléter une collection plutôt que de parcourir une liste à plat.

En tant qu'admin,
je veux ranger chaque succès dans une collection et récompenser une collection complète,
afin de construire des séries (Re:Zero, LAN, runs hebdo…) qui se lisent comme un tout.

## Contexte

Discussion avec Jean (2026-10-08), après la refonte de l'admin des succès (story 30.51) : des catégories
n'apportent rien côté admin (recherche, filtres et familles de critère suffisent), mais côté public un membre pense
en thèmes, pas en familles de critère. Déclencheur : la série Re:Zero, dont le premier succès existe déjà en
production (« Je t'aime, je t'aime, je t'aime… », `witch_of_envy`, qui débloque le cadre Mains de l'Envie).

Aujourd'hui, le catalogue public (`/joueurs/{slug}/succes`, `AchievementsCataloguePage`) liste tous les succès
d'un bloc, obtenus d'abord puis à obtenir, et la section « Succès » du profil (`ProfileAchievements`) montre les
derniers débloqués et le compte global.

## Critères d'acceptation

1. **Une collection** a un nom, une description courte, une image optionnelle, une position, un drapeau
   « secrète », et une récompense optionnelle pour la série complète : un cosmétique (même sélecteur que les succès,
   story 41.28), des pelles, ou les deux. Gérée depuis l'admin des succès.
2. **Un succès** appartient à zéro ou une seule collection, choisie (ou créée à la volée) dans son panneau de
   modification. Un succès sans collection reste dans « Autres succès ». Les succès inactifs ne comptent ni dans le
   total ni dans la progression d'une collection, comme dans le catalogue actuel.
3. **Catalogue public** : une section par collection (nom, description, image), avec la progression du joueur
   (« 3 / 7 » et une barre) et ses succès dans l'ordre de l'admin ; « Autres succès » à la fin. Une collection
   complète est mise en avant (bordure dorée, « Collection complète »).
   **Collection secrète** : absente du catalogue et du profil d'un joueur tant qu'il n'a débloqué aucun de ses
   succès ; ses succès n'apparaissent pas non plus dans « Autres succès » ni dans le total global de ce joueur.
   Dès le premier succès débloqué, la collection apparaît (avec un marqueur « Secrète »).
4. **Profil** : sous le compte global, les collections commencées avec leur progression (les trois plus avancées,
   lien vers le catalogue).
5. **Collection complète** : quand le dernier succès d'une collection est débloqué, la récompense de la collection
   est donnée une seule fois : le cosmétique (origine « collection ») et/ou les pelles (mouvement de pelles à clé
   unique, comme les quêtes), avec une notification « Collection complète : … ».
   Un succès ajouté ensuite à une collection déjà complétée ne retire rien ; la récompense reste acquise.
6. **Admin des succès** : une section par collection dans la liste ; le glisser-déposer range un succès dans sa
   collection et peut le faire passer d'une collection à l'autre ; le filtre « Collection » rejoint les autres
   filtres ; les collections se créent, se renomment, se réordonnent et se suppriment (un succès d'une collection
   supprimée retourne dans « Autres succès »).
7. **Première collection** : « Re:Zero », avec « Je t'aime, je t'aime, je t'aime… » dedans (créée depuis l'admin,
   pas par une migration de données).
8. Gates verts ; tests (progression, collection complète et récompense unique, admin, rendu du catalogue).

## Décisions de Jean (2026-10-08)

1. Une seule collection par succès.
2. Collections secrètes : visibles seulement une fois le premier de leurs succès débloqué par le joueur.
3. La récompense de collection peut être un cosmétique, des pelles, ou les deux.
4. Les succès inactifs ne comptent pas, comme dans le catalogue actuel.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Domaine : entité `AchievementCollection` (nom, description, image, position, secrète,
  récompense cosmétique, pelles), colonne `collection_id` sur la définition de succès ; migration ; API admin (CRUD,
  ordre, rattachement d'un succès) ; tests.
- [x] **Task 2** (AC 5) - Récompense de collection complète au déblocage (dans le recalcul des succès) : cosmétique
  et pelles, une seule fois, notification ; tests.
- [x] **Task 3** (AC 3, 4) - API publique et front : sections du catalogue, progression, profil, collections secrètes
  masquées tant qu'aucun succès n'est débloqué ; tests.
- [x] **Task 4** (AC 6) - Admin des succès : sections par collection, glisser-déposer entre collections, filtre,
  gestion des collections ; tests.
- [ ] **Task 5** (AC 7, 8) - Création de « Re:Zero » une fois déployé ; gates.

## Dépendances

- Story 30.51 (admin des succès, glisser-déposer) : à merger avant.

## Dev Agent Record

- API :
  - `AchievementCollection` (nom, description, image, position, secrète, cosmétique, pelles) et
    `AchievementCollectionCompletion` (une ligne par membre et collection, unique) ; `collection_id` sur la
    définition ; migration `Version20261008090000`.
  - `AdminAchievementCollectionService` + `AdminAchievementCollectionController` (création, modification, ordre,
    suppression : les succès retournent dans « Autres succès ») ; la liste arrive dans `meta.collections` du tableau
    de bord ; `collectionId` sur la création et la modification d'un succès ; `reorder` accepte `collections`
    (id du succès => collection) pour le glisser-déposer entre sections.
  - `CollectionCompletionRewarder::settle()` appelé à chaque recalcul et après une attribution manuelle : la
    collection complète (tous ses succès actifs) est écrite une fois, le cosmétique est donné (origine
    `collection`), les pelles passent par le port `PelleRewardInterface` (Wallet `LedgerPelleReward`,
    `PelleReason::CollectionReward`, clé `collection:{id}:{user}`), une notification `collection_completed`.
  - `CommunityProfileView` : sections et progression dans le catalogue, les trois collections les plus avancées sur
    le profil, collection secrète (et ses succès, et le total) masquée tant qu'aucun succès n'est débloqué.
  - Wallet « Ma collection » : une collection apparaît parmi les façons de gagner un cosmétique (anonyme si secrète).
- Front : `achievement-collections.tsx` (sections du catalogue, barre, profil), `achievement-sections.ts`
  (sections de l'admin, déplacement entre sections), admin en sections avec menu de collection, filtre
  « Collection », dialogue de collection (secrète, image, cosmétique, pelles), suppression, collection choisie ou
  créée dans le formulaire du succès ; notification « Collection complète ».
- Tests : `AchievementCollectionTest` (admin, secret, récompense unique), `achievement-collections.test.tsx`.
- Task 5 (créer « Re:Zero » avec `witch_of_envy`) : à faire par Jean après le déploiement.
