# Story 41.49: Préparer le blind test étape par étape

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-25
**Dépôt :** `ArchiLAN-dev/archilan-blind-test` (app)
**Origine :** Jean le 2026-10-25, sur la maquette https://claude.ai/artifact/QWn8Qes1h7CbDWxQT7thrs (version 4) :
l'onglet « Préparer » de la 41.48 en parcours guidé, une étape à la fois, sans YAML ni sous-menus (fenêtres pour les
réglages et l'import de fichier).

## Story

En tant qu'animateur,
je veux préparer le blind test en quatre étapes guidées (créneau, playlist, relecture, lancement),
afin d'obtenir une playlist qui couvre exactement le créneau, déjà vérifiée, sans toucher au YAML.

## Critères d'acceptation

1. **Parcours** : en tête de l'onglet, 4 étapes cliquables (Créneau, Playlist, Relire, Lancer) avec leur état en une
   ligne ; une seule étape visible ; « Retour » et « Suivant » ; Relire et Lancer restent fermées tant qu'il n'y a
   pas de playlist. L'étape d'arrivée est la première à faire.
2. **Créneau** : début et fin, pré-remplis avec les dates de l'événement (liste du site) et gardés par brouillon ;
   la console affiche la durée et le nombre de morceaux estimé (192 s par morceau en moyenne).
3. **Playlist** :
   - « Générer la playlist » tire des mashups jusqu'à couvrir le créneau, en vérifiant chaque vidéo retenue au
     passage (les mortes sont écartées et comptées) ; la playlist arrive vérifiée.
   - « Réglages du tirage » (fenêtre) : durées min. et max., catégorie, graine, blagues visuelles ; gardés par
     brouillon.
   - « Importer un fichier » (fenêtre) : un fichier invalide est refusé avec la liste des problèmes, sans toucher à
     la playlist actuelle.
   - Playlist prête : résumé (morceaux, durée, date de vérification), « Revérifier les vidéos » (avec « Retirer les
     vidéos mortes »), « Compléter jusqu'à la fin du créneau » (ajoute des morceaux vérifiés, jamais une vidéo déjà
     présente), « Tout régénérer » (confirmation), « Exporter en .yml ».
4. **Relire** (facultatif) : tableau paginé (50 par page) des morceaux, avec le titre du rip, la durée et les œuvres ;
   filtres « À préciser » (sources `other`, par défaut) et « Tous », recherche. Une ligne se déplie : type de chaque
   œuvre en boutons, réponse, autres orthographes ; « Enregistrer », « Écouter sur YouTube », « Retirer ce morceau »
   (deuxième clic pour confirmer). Modifier les œuvres garde la vérification des vidéos.
5. **Lancer** : bilan (créneau couvert, vidéos vérifiées, œuvres à préciser, partie à jour) puis « Mettre la playlist
   dans la partie » (avertissements partie déjà commencée / autre partie active de la 41.48), puis la marche à suivre
   du jour J.
6. La playlist garde le titre de chaque rip (`title`, facultatif dans le YAML) ; l'import du wiki le remplit.
7. Le brouillon n'est plus jamais invalide : enregistrer un YAML invalide est refusé (422 + problèmes).

## Tâches

- [x] `Track::title`, chargeur, écriture, import du wiki.
- [x] Import : cible en secondes, vérification au passage, mode « compléter » (ajout, sans doublon de vidéo).
- [x] Brouillon : créneau et réglages du tirage (migration) ; API des morceaux (liste filtrée et paginée, modifier,
      retirer) ; refus d'un YAML invalide.
- [x] Console : onglet Préparer en 4 étapes, deux fenêtres.
- [x] Tests ; vérification dans Chrome.

## Dev Agent Record

- App : branche `feature/41-49-preparer-par-etapes`, posée sur `feature/41-48-console-ux`. Migration
  `Version20261025143530` (`slot_start`, `slot_end`, `draw_settings`).
- Import : cible en secondes (créneau, ou ce qui manque pour « Compléter »), lots de 40 titres quand chaque vidéo est
  vérifiée, doublons de vidéo écartés, numéros des nouveaux morceaux après le dernier.
- Vérifié dans Chrome sur l'app locale avec le site local : liste des événements, 4 étapes, deux fenêtres,
  génération réelle sur le wiki et YouTube (créneau de 10 min : liste de la catégorie en une minute environ, 6 morceaux
  vérifiés), relecture enregistrée. Événement de test supprimé ensuite de la base locale.
