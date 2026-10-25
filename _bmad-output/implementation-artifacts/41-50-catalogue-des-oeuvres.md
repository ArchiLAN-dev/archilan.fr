# Story 41.50: Le catalogue des œuvres et la réponse choisie dans une liste

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-25
**Dépôt :** `ArchiLAN-dev/archilan-blind-test` (app)
**Origine :** Jean le 2026-10-25 : les « autres orthographes acceptées » sont fragiles ; télécharger en amont ce que
propose le wiki SiIvaGunner, y ajouter les réponses des playlists, et faire choisir la réponse dans une liste avec
autocomplétion. Choix retenus : choix obligatoire dans la liste, catalogue tiré des mashups.

## Story

En tant que joueur,
je veux choisir l'œuvre que je reconnais dans une liste qui se complète pendant que je tape,
afin que ma réponse soit comprise à coup sûr, quelle que soit mon orthographe.

## Critères d'acceptation

1. **Catalogue** : une table des œuvres, chacune avec sa popularité (nombre de rips) :
   - les jeux de base des mashups (le titre de chaque rip, après le dernier « - ») ;
   - les œuvres des catégories « Rips featuring X » (sans les « minor sources ») ;
   - les réponses de toute playlist chargée ou relue, ajoutées si elles manquent.
   Il est rafraîchi à chaque génération de playlist (la liste de la catégorie est déjà lue), par « Mettre à jour le
   catalogue » dans la console (par étapes courtes) et par `app:catalogue:mettre-a-jour`.
2. **Recherche** : insensible aux accents, à la casse, à la ponctuation et à l'ordre des mots ; chiffres romains égaux
   aux chiffres ; sigles (« ff7 » trouve « Final Fantasy VII »). Les correspondances exactes puis les débuts de nom
   d'abord, puis les plus populaires ; 8 suggestions. Les suggestions ne dépendent jamais de la playlist en cours.
3. **Téléphone** : le champ propose les suggestions (clavier : flèches, Entrée, Échap ; doigt) ; « Proposer » n'est
   actif qu'une œuvre choisie. `POST /api/propositions` prend `{workId}` ; un texte libre est refusé (422). Le
   jugement compare l'œuvre choisie aux œuvres du morceau ; règles anti-spam inchangées.
4. **Console** : la relecture choisit la réponse dans le même catalogue (un nom absent peut être gardé : il entre dans
   le catalogue) ; le champ « autres orthographes » disparaît de l'écran (le YAML les garde). L'étape Playlist affiche
   la taille du catalogue, sa date, et « Mettre à jour le catalogue ».

## Tâches

- [x] Entité `Work` et migration ; `WorkCatalog` (recherche, ajout, apprentissage depuis le wiki).
- [x] `SiivaWiki::featuredWorks()` ; catalogue nourri par l'import, par la console (étapes) et par une commande.
- [x] Propositions par œuvre choisie ; recherche côté joueur et côté console.
- [x] Téléphone et console ; tests ; vérification dans Chrome.

## Dev Agent Record

- App : branche `feature/41-50-catalogue-oeuvres`, posée sur `feature/41-49-preparer-par-etapes`. Migration
  `Version20261025145730` (table `work`). Composant partagé `public/work-picker.js` (téléphone et console).
- Catalogue réel tiré du wiki en local : 3 738 œuvres en une minute environ. « ff7 » → Final Fantasy VII, « mario 64 »
  → Super Mario 64, « zelda ocarina » → Ocarina of Time, « undertal » → Undertale.
- Limite constatée : le wiki nomme les œuvres en anglais (« Pokémon Red », pas « Pokémon Rouge ») ; un titre français
  ne trouve rien. Piste : noms français ajoutés au catalogue (autre story).
- Une clé normalisée purement numérique (œuvre « 2 ») devient une clé entière dans un tableau PHP : convertie en
  chaîne à la création (test dédié).
