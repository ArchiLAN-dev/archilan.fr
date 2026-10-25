# Story 41.46: Console d'animation et page joueur du blind test

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-24
**Dépôts :** `ArchiLAN-dev/archilan-blind-test` (app) et `archilan.fr` (site, lien vers la console)
**Origine :** Jean le 2026-10-24 : préparer la partie demande des commandes sur le serveur, l'admin ne voit ni la
partie ni les crédits, et le joueur ne voit que le morceau en cours. « Faut améliorer ça. »

## Story

En tant qu'animateur du blind test,
je veux préparer la playlist et suivre la partie depuis mon navigateur,
afin de ne plus passer par le terminal du serveur et de voir tout de suite un souci de crédit.

En tant que joueur,
je veux voir les réponses du morceau précédent, ma place et ce que j'ai trouvé,
afin de suivre la partie depuis mon téléphone sans regarder l'écran de salle.

## Critères d'acceptation

### Console (`/admin` dans l'app)

1. **Accès** : `/admin` s'ouvre avec le même `REGIE_TOKEN` que la régie (`?token=` une fois, la session s'en
   souvient) ; sans jeton configuré, fermée. La page dit qu'elle ne se projette pas (elle montre les réponses).
   `?event=<id>` préremplit l'événement.
2. **Brouillon** : un brouillon de playlist par événement, gardé en base. La console l'affiche dans un éditeur YAML,
   l'enregistre et donne son bilan : nombre de morceaux, durée totale, sources de type `other` à préciser, et la
   liste complète des problèmes du chargeur. Un fichier YAML peut être ouvert depuis le poste, le brouillon
   téléchargé (`/admin/brouillon.yml?event=`).
3. **Import du wiki** : mêmes options que `app:playlist:importer-wiki` (nombre, durées, blagues visuelles, graine,
   catégorie). L'import avance par petites étapes lancées par la page (une requête courte chacune : une page de la
   catégorie, puis 100 pages du wiki), avec une barre de progression ; il remplace le brouillon une fois fini.
   Recharger la page reprend l'import là où il en était ; « Annuler » l'abandonne.
4. **Vérification des vidéos** : par lots de 20 lancés par la page, avec progression ; les vidéos mortes ou non
   intégrables sont listées, « Retirer les vidéos mortes » les enlève du brouillon. Une vidéo sans réponse de
   YouTube est signalée et gardée.
5. **Chargement** : « Charger pour la partie » fait du brouillon (valide) la partie active de l'événement. Si cette
   partie a déjà démarré, il faut cocher « Recommencer la partie du début » ; sinon 409.
6. **Partie** (rafraîchie toutes les 3 s) : événement, état de la régie (attente, antenne, perdue), morceau n / total
   avec son id, sa vidéo et ses réponses, état du lecteur ; nombre de joueurs et de sources trouvées ; crédits
   créditées, déjà créditées, refusées, en attente (avec le plus ancien) et « Renvoyer les crédits en attente » ;
   les 20 dernières trouvailles (heure, joueur, réponse, morceau) et les 20 derniers crédits refusés. Liens vers la
   régie et l'écran.
7. Les commandes existantes restent ; l'import et la vérification partagent leur code avec la console.

### Page joueur (`/jouer`)

8. Sous le morceau en cours : les réponses du morceau précédent une fois sa grâce passée (comme l'écran de salle),
   la place du joueur (« 3e sur 25 », ex aequo au même rang) et la liste de ce qu'il a trouvé sur toute la partie,
   la plus récente en tête (50 au plus), avec le numéro du morceau.

### Site

9. Le bloc « Blind test » de l'admin des pelles affiche l'identifiant de l'événement (copiable) et, quand l'app est
   configurée, « Ouvrir la console » vers `<BLIND_TEST_APP_URL>/admin?event=<id>`. `GET` des réglages renvoie
   `consoleUrl` (ou `null`).

## Tâches

- [x] App : entité `PlaylistDraft` et migration ; service d'import par étapes (`WikiImport`) partagé avec la commande.
- [x] App : `AdminController` (page, brouillon, import, vérification, chargement, partie, crédits) et template.
- [x] App : état joueur enrichi (`GameService::playerState`), template `/jouer`.
- [x] App : tests fonctionnels (accès, brouillon, import avec wiki simulé, vérification, chargement, partie, page
      joueur).
- [x] Site : `consoleUrl` dans les réglages admin, bloc admin, tests ; doc de déploiement.

## Notes

- Pas de tâche de fond ni de file : chaque étape tient dans une requête courte, la page enchaîne. Le premier appel
  au wiki d'une étape garde la pause polie de `SiivaWiki` (1 s par requête).
- Le brouillon réécrit par « Retirer les vidéos mortes » passe par `PlaylistWriter` : les commentaires YAML sont
  perdus, l'en-tête est réécrit.

## Dev Agent Record

- App : PR `archilan-blind-test` (branche `feature/41-46-console`). `WikiImport` + `ImportProgress` découpent l'import ;
  la commande `app:playlist:importer-wiki` enchaîne les mêmes étapes. Brouillon : table `playlist_draft`
  (migration `Version20261025123330`), avec l'import en cours en JSON (les ~14 000 titres de la catégorie y restent
  le temps de l'import).
- Le chargement passe par `GameService::load` comme la commande ; 409 `game_started` sans « Recommencer ».
- Vérifié dans Chrome sur l'app locale : brouillon, vérification réelle sur YouTube (une vidéo inventée signalée
  supprimée puis retirée), chargement, vue de la partie, import réel de 3 morceaux du wiki (graine 42, environ une
  minute pour lister la catégorie).
- Site : `consoleUrl` dans `GET/PUT /api/v1/admin/events/{id}/blind-test` ; fonctionnels (`BlindTestTest`) laissés à
  la CI, Postgres local éteint.
