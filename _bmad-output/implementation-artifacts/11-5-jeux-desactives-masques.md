# Story 11.5: Masquer les jeux désactivés hors de l'admin

**Status:** ready-for-dev
**Epic:** 11 - Bibliothèque de jeux admin
**Date:** 2026-10-02
**Remplace une décision de :** 11.4 (« un jeu désactivé reste visible dans les sélecteurs mais ne peut plus être
choisi »)

## Story

En tant que joueur,
je ne veux plus voir les jeux désactivés dans les listes du site,
afin de ne pas tomber sur des jeux que je ne peux pas choisir.

En tant que joueur d'une partie passée ou en cours,
je veux que le jeu de mon slot reste affiché même s'il a été désactivé depuis,
afin que mon historique, mes récaps et ma partie restent compréhensibles.

## Contexte

Demande de Jean (2026-10-02). La story 11.4 laissait un jeu désactivé visible, grisé, dans les sélecteurs. Le
nouveau principe : **un jeu désactivé disparaît partout où l'on découvre ou choisit un jeu, sauf côté admin ; il
reste là où il fait partie de l'histoire d'une partie.**

Aujourd'hui (inventaire du 2026-10-02) : seul le bouton « créer une partie avec ce jeu » (17.23) filtre les jeux
désactivés. Les deux sélecteurs les renvoient grisés, et tout le reste ignore `disabled_at` (catalogue, recherche,
sitemap, favoris, couplage Steam, hebdos).

## Critères d'acceptation

### Masqué (surfaces de découverte et de choix)

1. **Catalogue public** `GET /api/v1/games` (liste, `?all=1`, recherche, pagination) : les jeux désactivés sont
   exclus de la liste **et des totaux**. La page `/jeux`, ses filtres et ses catégories ne les montrent donc plus.
2. **Sitemap** : plus d'entrée `/jeux/{slug}` pour un jeu désactivé.
3. **Couplage Steam** (`POST /api/v1/games/steam-coupling`) : un jeu désactivé ne compte pas parmi « tes jeux
   Steam jouables ».
4. **Sélecteur d'inscription à un event** et **sélecteur d'une partie privée** : un jeu désactivé n'est plus
   proposé dans `availableGames`. Le refus à l'enregistrement (11.4) reste.
5. **Jeux récemment joués** (partie privée) : un jeu désactivé n'est plus proposé.
6. **Favoris** : l'éditeur ne propose plus un jeu désactivé et l'enregistrement le refuse s'il est ajouté. Sur un
   profil (public et édition), un favori désactivé **n'est pas affiché mais reste enregistré** : il revient si le
   jeu est réactivé.
7. **Listes « possédés » / « prévus »** : un jeu désactivé ne s'affiche pas (la liste suit le catalogue) et ne peut
   pas y être ajouté ; les entrées existantes restent enregistrées.

### Conservé (historique et parties en cours)

8. Le **nom du jeu d'un slot existant** reste affiché partout : sélecteurs (slot déjà choisi avant la
   désactivation), détail d'une partie, résultats, récaps, timeline, historique d'un joueur, partie en cours,
   présence « En jeu ». Le libellé d'un slot existant ne doit jamais retomber sur l'identifiant du jeu (piège : les
   sélecteurs résolvent aujourd'hui le nom depuis la liste des jeux proposés).
9. **Page publique du jeu** `/jeux/{slug}` : reste accessible par lien direct (liens de l'historique, d'un profil,
   d'une partie), avec un bandeau « Ce jeu est temporairement désactivé » (et le message de l'admin s'il y en a un).
   Elle n'a plus de bouton de création de partie (déjà le cas) et n'est plus indexée (`noindex`).
10. **Hebdos** : la story ne change ni la génération ni le lancement des hebdos (voir « Hors périmètre ») ; une
    hebdo existante continue de s'afficher.

### Admin

11. Côté admin, rien ne change : les jeux désactivés restent listés, filtrables et éditables.

12. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1, 2, 3) - `DbalGameCatalogQuery` (liste, totaux, recherche), `DbalSteamCatalogQuery` ;
      sitemap côté front (suit le catalogue) ; tests.
- [ ] **Task 2** (AC 4, 5, 8) - `RegistrationGameSelection`, `PersonalRunGameSelection` : `availableGames` sans les
      jeux désactivés ; nom des slots résolu à part (par identifiant, sans filtre) ; front : libellés des slots
      sélectionnés depuis les slots, pas depuis `availableGames` ; tests (adapter
      `RegistrationGameSelectionTest::testPutRejectsNewlyAddedDisabledGame`).
- [ ] **Task 3** (AC 6, 7) - Favoris : éditeur, `UpdateCommunityProfile::parseFavorites`,
      `CommunityProfileView::resolveFavoriteGames` ; listes possédés / prévus ; tests.
- [ ] **Task 4** (AC 9) - Page `/jeux/{slug}` : bandeau, message, `noindex` ; tests.
- [ ] **Task 5** (AC 12) - Gates et vérification visuelle (catalogue, sélecteur avec un slot sur un jeu désactivé,
      page du jeu, profil avec un favori désactivé).

## Hors périmètre

- **Génération des hebdos** : aujourd'hui, une hebdo d'un jeu désactivé est encore générée et lancée. Faut-il la
  sauter ? C'est un changement de comportement, pas d'affichage : à trancher à part.
- Les modèles de YAML (`/api/v1/yaml-templates`) ne vérifient que `isApworldReady` ; un jeu désactivé n'y est
  atteignable qu'à travers un slot existant, ce qui reste voulu.
