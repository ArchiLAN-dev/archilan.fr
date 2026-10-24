# Story 41.42: App de blind test, régie, téléphones et écran

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-24
**Dépôt :** `ArchiLAN-dev/archilan-blind-test`
**Origine :** Jean le 2026-10-24 (« go la suite sur le blind test »). Suite de la 41.41 (le cœur).

## Story

En tant qu'équipe ArchiLAN,
je veux une app jouable de bout en bout dans la salle,
afin de lancer la playlist sur la sono, de laisser les inscrits proposer depuis leur téléphone et de voir les pelles
arriver sur archilan.fr.

## Critères d'acceptation

1. **Partie sauvegardée** (SQLite via Doctrine) : la partie de l'event (playlist chargée, état de l'horloge), l'état
   de chaque joueur par morceau, les sources trouvées et la file des crédits. Un redémarrage du serveur reprend au
   bon morceau, à la bonne seconde.
2. **Entrée** : `GET /rejoindre?token=…` vérifie le jeton du site, ouvre une session joueur (cookie de session
   Symfony) et redirige vers `/jouer`. Jeton invalide ou expiré : une page qui le dit.
3. **Téléphone** (`/jouer`) : numéro du morceau en cours (jamais son titre), un champ de proposition, le verdict
   (trouvé, déjà trouvé, faux, trop rapide, plus d'essai), les sources trouvées sur ce morceau, le score du joueur.
   Rafraîchi toutes les 2 s.
4. **Proposition** (`POST /api/propositions`) : jugée contre les morceaux acceptés à cet instant (en cours, et le
   précédent pendant 10 s), avec les règles anti-spam de la 41.41 ; une source trouvée part au site tout de suite
   (`CreditClient`) et reste en file si le site ne répond pas.
5. **Régie** (`/regie`, protégée par `REGIE_TOKEN`) : joue le fichier du morceau en cours à la position donnée par
   le serveur, se recale si l'écart dépasse 2 s ; boutons démarrer, pause, reprendre, suivant, rejouer. Les fichiers
   audio ne sont servis qu'à la régie.
6. **Écran de salle** (`/ecran`, même jeton) : morceau en cours, réponses du morceau précédent une fois sa fenêtre
   close, classement des 10 premiers.
7. **File des crédits** : `app:credits:renvoyer` renvoie les crédits en attente (réseau, 5xx), abandonne les refus
   définitifs ; prévu pour tourner en boucle (`--boucle`).
8. **Chargement** : `app:partie:charger <event> <playlist.yml>` charge la playlist d'un event et prépare la partie.
9. **Hors périmètre** : Mercure (le rafraîchissement par interrogation suffit dans une salle), image Docker et
   déploiement (story suivante).

## Tâches

- [x] Doctrine + SQLite, entités et migration.
- [x] Service de partie (horloge, propositions, crédits).
- [x] Contrôleurs : entrée, téléphone, régie, écran, API.
- [x] Pages Twig + JavaScript sans build.
- [x] Commandes `app:partie:charger`, `app:credits:renvoyer`.
- [x] Tests fonctionnels (entrée, proposition, régie protégée, crédit en file).

## Dev Agent Record

- PR `archilan-blind-test#1` (branche `feature/41-42-pages`).
- Rafraîchissement par interrogation (téléphone 2 s, régie 1 s, écran 3 s) au lieu de Mercure : une salle de LAN
  n'en a pas besoin et l'app reste sans hub à déployer.
- Pages en Twig et JavaScript sans build, au lieu de React : trois pages simples, rien à compiler en salle.
- Une proposition est chronométrée et comptée sur le morceau en cours, même quand elle trouve une source du
  précédent pendant sa grâce ; sinon la grâce rouvrirait l'anti-spam.
- 43 tests (8 fonctionnels de bout en bout, faux site `SiteStub`) ; essai manuel par HTTP.
