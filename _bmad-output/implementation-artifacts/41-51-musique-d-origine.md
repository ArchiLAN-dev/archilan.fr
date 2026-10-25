# Story 41.51: La musique d'origine, réponse quand elle se reconnaît

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-25
**Dépôt :** `ArchiLAN-dev/archilan-blind-test` (app)
**Origine :** Jean le 2026-10-25 : sur « Phantom Pt II - AudioSurf », on entend « Phantom Pt II » (Justice), le jeu
est impossible à deviner et la musique n'est pas une réponse. Demande : une automatisation, sans liste à tenir.

## Story

En tant que joueur,
je veux pouvoir répondre par la musique d'origine d'un mashup quand elle est reconnaissable,
afin de marquer sur ce que j'entends vraiment, même quand le jeu ne se devine pas à l'oreille.

## Critères d'acceptation

1. **Musique d'origine** : la partie du titre du rip avant le dernier « - » (« Phantom Pt II » dans
   « Phantom Pt II - AudioSurf »).
2. **Générique ou pas, automatiquement**, d'après la liste des mashups que l'import lit déjà : une musique est
   générique si elle apparaît avec au moins deux jeux différents, ou si elle n'est faite que de mots génériques et de
   numéros (« Title Theme », « Boss Battle 2 », « Track 3 »…). Une musique générique n'est jamais une réponse.
3. **Réponse** : à la génération, une musique d'origine non générique devient une œuvre à trouver, de type
   « Musique » (nouveau type `music`), sauf si elle double déjà une réponse du morceau.
4. **Catalogue** : il apprend toutes les musiques d'origine non génériques des mashups, pour que les suggestions
   restent larges et ne trahissent pas la playlist.
5. **Playlists existantes** : à l'étape Playlist, « Ajouter les musiques d'origine aux réponses » applique la même
   règle aux morceaux qui ont un titre de rip, sans régénérer ; la vérification des vidéos est gardée.
6. Le type « Musique » s'affiche partout (téléphone, écran de salle, console).

## Tâches

- [x] `SourceType::Music` ; règle du générique ; catalogue des musiques.
- [x] Import : réponse « Musique » ; action sur une playlist existante.
- [x] Écrans ; tests ; vérification sur le wiki réel.

## Dev Agent Record

- App : branche `feature/41-51-musique-d-origine`. Migration `Version20261025154748` (`work.music`).
- Règle dans `OriginalMusic` (mots génériques + « un seul jeu ») ; `WorkCatalog::learnMusic` marque les musiques
  retenues et démarque celles devenues partagées ; l'import ajoute la réponse via `isAnswerMusic`.
- Sur le wiki réel (catalogue local, 41 s) : 11 489 musiques retenues sur 15 139 œuvres. « Phantom Pt II »,
  « Green Hill Zone », « Circus (Real Mix) » retenues ; « Title Theme » écartée ; « Megalovania » écartée comme
  musique (plusieurs jeux) mais reste une réponse comme œuvre « Rips featuring ».
