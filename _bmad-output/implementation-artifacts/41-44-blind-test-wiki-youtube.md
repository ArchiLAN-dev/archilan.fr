# Story 41.44: Blind test sur YouTube, playlist tirée du wiki SiIvaGunner

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-24
**Dépôts :** `ArchiLAN-dev/archilan-blind-test` (app) et `archilan.fr` (déploiement, documentation)
**Origine :** Jean le 2026-10-24 : utiliser le wiki SiIvaGunner et les liens YouTube ; il a YouTube Premium ; « la
synchronisation, il faut une valeur sûre pour savoir ce qui se joue ».

## Story

En tant qu'équipe ArchiLAN,
je veux une playlist importée du wiki et jouée sur YouTube par la régie, dont le lecteur fait foi,
afin de ne rien annoter ni héberger à la main, et de juger chaque proposition contre le morceau réellement entendu.

## Contexte

- Le wiki (API MediaWiki de `siivagunner.fandom.com`) donne pour chaque rip un bloc `{{Rip}}` (`link` = id YouTube,
  `length`), le jeu de base dans le titre (« Morceau - Jeu ») et les autres sources en catégories « Rips featuring
  X ». Catégorie « Mashups » : 14 133 rips ; « Rips with visual elements » : 3 492. Contenu sous CC BY-SA.
- La 41.42 jugeait contre une horloge calculée par le serveur. Avec YouTube (chargement, coupure, vidéo bloquée),
  seul le lecteur sait ce qui joue.

## Critères d'acceptation

1. **Playlist YouTube** : un morceau porte `youtube` (id de vidéo) et sa durée ; plus de fichiers audio. Nouveau type
   de source `other` (« Œuvre ») pour les sources dont on ignore le genre.
2. **Import** `app:playlist:importer-wiki <sortie.yml>` : parcourt « Mashups » par l'API (requêtes espacées, User-Agent
   identifié), mélange (graine fixée par option), retient jusqu'à `--nombre` rips selon la durée (`--duree-min`,
   `--duree-max`) en écartant par défaut les blagues visuelles ; sources = jeu de base + catégories « Rips featuring
   X » (sans doublon). Écrit le YAML relisible.
3. **Vérification** `app:playlist:verifier <playlist.yml> [--sortie]` : chaque vidéo via l'oEmbed YouTube (404 =
   supprimée, 401/403 = intégration interdite) ; liste les mortes et écrit au besoin la playlist sans elles.
4. **Le lecteur fait foi** :
   - le serveur tient l'ordre (index demandé) ; la régie confirme la mise à l'antenne quand le lecteur passe
     réellement en lecture ; seul un morceau confirmé est « à l'antenne » ;
   - les propositions sont jugées contre le morceau à l'antenne, et le précédent pendant 10 s après le changement ;
   - la régie envoie un signal toutes les 2 s (état, position) ; sans signal depuis 15 s, la régie est perdue :
     téléphones « en attente de la régie », propositions refusées ;
   - fin de vidéo, erreur ou « Suivant » : la régie demande le suivant au serveur, qui avance l'index (idempotent) ;
   - journal horodaté de chaque mise à l'antenne ;
   - une seule régie : une nouvelle prend la main, l'ancienne passe en veille ;
   - une régie rechargée reprend au morceau à l'antenne, à sa dernière position.
5. **Régie** : lecteur YouTube intégré (API IFrame) ; la page rappelle de ne pas la projeter (titre et blagues
   visuelles). **Écran** : crédit du wiki.
6. **Déploiement** : plus de dossier audio (`AUDIO_DIR`, volume) ; procédure mise à jour (import, vérification,
   cookies tiers youtube.com autorisés sur le PC de régie pour Premium).

## Tâches

- [x] Playlist : `youtube`, type `other`.
- [x] Import wiki + vérification oEmbed.
- [x] Modèle « à l'antenne » (jeu, journal, régie unique), service et API régie.
- [x] Régie YouTube, écran.
- [x] Retrait de l'horloge calculée et de l'audio.
- [x] Tests ; monorepo (compose, env, documentation).

## Dev Agent Record

- App : PR `archilan-blind-test#3`. Monorepo : compose (plus de volume audio), `.env.prod.example`, procédure.
- Essai réel de l'import : 5 morceaux (graine 42), tous jouables à la vérification. Les catégories « X minor sources »
  sont des catégories de tri, pas des réponses : écartées.
- Essai réel de la régie dans Chrome : mise à l'antenne à la lecture effective, « Suivant », journal, prise de main
  par un deuxième onglet (le premier passe en veille et coupe sa vidéo).
- La pause et la reprise n'agissent que sur le lecteur ; le serveur les voit par le signal. Une vidéo en pause reste
  « à l'antenne » tant que la régie signale (les joueurs ont encore le morceau en tête).
