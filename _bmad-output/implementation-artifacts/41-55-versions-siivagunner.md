# Story 41.55: La musique sans sa « version » SiIvaGunner

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-26
**Dépôt :** `ArchiLAN-dev/archilan-blind-test` (app)
**Origine :** Jean le 2026-10-26 : beaucoup de rips s'appellent « Music Name (Version) - Jeu ». La parenthèse est la
façon de SiIvaGunner de remettre la même musique sur sa chaîne en annonçant vaguement la blague. Elle ne correspond
jamais à ce qu'on entend : impossible à deviner sans connaître la chaîne par cœur.

## Story

En tant que joueur,
je veux qu'on attende de moi le nom de la musique, sans la « version » SiIvaGunner,
afin de marquer sur ce que je reconnais vraiment.

## Critères d'acceptation

1. **Musique d'origine** (règle de 41.51 complétée) : les groupes entre parenthèses ou crochets en fin de musique sont
   retirés, même s'il y en a plusieurs (« Green Hill Zone (Beta Mix) [JP] - Sonic » donne « Green Hill Zone »). Une
   parenthèse au milieu du titre reste. Une musique qui n'est qu'une parenthèse n'est pas une musique.
2. **Générique ou pas** : la règle « un seul jeu » regroupe désormais toutes les versions d'une même musique sous un
   seul nom.
3. **Réponse** : à la génération et via « Ajouter les musiques d'origine aux réponses », la réponse « Musique » est le
   nom sans version.
4. **Playlists existantes et proposition** : face à une réponse « Musique », la version est ignorée des deux côtés.
   Une réponse déjà enregistrée « Green Hill Zone (Beta Mix) » accepte « Green Hill Zone », et une proposition
   « Green Hill Zone (Rejected Mix) » choisie dans les suggestions est acceptée pour « Green Hill Zone ». Rien ne
   change pour les autres types (jeu, œuvre…).
5. **Catalogue** : au rafraîchissement, les œuvres venues seulement des musiques (`origin = music`) dont le nom porte
   une version sont retirées, pour que les suggestions ne proposent plus dix versions de la même musique.

## Tâches

- [x] `OriginalMusic::withoutVersion` ; `of()` l'applique.
- [x] `AnswerMatcher` : comparaison sans version pour une source « Musique ».
- [x] `WorkCatalog::learnMusic` : retrait des œuvres musique à version.
- [x] Tests : `OriginalMusicTest`, `AnswerMatcherTest`, `CatalogTest`.
- [x] `composer gates` de l'app.

## Hors périmètre

- Les versions dans le nom du jeu (après le dernier « - ») : les jeux viennent des catégories du wiki.
- Réécrire les playlists déjà générées : le correcteur les couvre (critère 4).
