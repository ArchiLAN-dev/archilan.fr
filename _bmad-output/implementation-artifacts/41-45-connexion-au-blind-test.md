# Story 41.45: Se connecter au blind test avec son compte archilan.fr

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-24
**Dépôts :** `archilan.fr` (site) et `ArchiLAN-dev/archilan-blind-test` (app)
**Origine :** Jean le 2026-10-24 : identifier le joueur sans lien partageable ; le partage du cookie de session entre
sous-domaines a été écarté (il retirerait la protection `__Host-` de tout le site), au profit d'un code à usage unique.

## Story

En tant que membre inscrit à un événement,
je veux entrer dans le blind test en un clic depuis le site, ou en scannant un QR code avec mon téléphone,
afin de jouer sous mon compte sans qu'un lien copié permette à quelqu'un d'autre de jouer à ma place.

## Critères d'acceptation

1. **Entrée** : sur la page de l'événement, un inscrit qui peut jouer voit « Jouer au blind test » et un QR code ;
   les deux mènent à `https://<app>/connexion`. `GET /api/v1/events/{eventId}/blind-test/access` (auth) donne cette
   adresse, ou 403 avec le motif (règles de la 41.40).
2. **Aller** : l'app crée un `state` (16 à 64 caractères `[A-Za-z0-9_-]`), le garde en session, et envoie le joueur sur
   `<site>/blind-test/connexion?event=<id>&state=<state>`.
3. **Site** : la page fait se connecter le joueur au besoin (retour sur la même page), puis
   `POST /api/v1/events/{eventId}/blind-test/codes {state}` crée un code à usage unique (stocké haché, valable 60 s)
   et renvoie l'adresse de retour `<app>/connexion/retour?code=…&state=…`. Cette adresse vient de `BLIND_TEST_APP_URL`,
   jamais de la requête : pas de redirection ouverte.
4. **Échange** : l'app vérifie que le `state` est le sien, puis
   `POST /api/v1/integrations/blind-test/codes/echange {code}`, signé comme les crédits ; le site répond une seule fois
   `{userId, eventId, displayName, exp}` (`exp` = fin de l'événement), après avoir revérifié les règles d'accès. Code
   inconnu, déjà servi ou expiré : 404.
5. **App** : ouvre sa session et mène à `/jouer` ; sans session, `/jouer` propose « Se connecter avec archilan.fr ».
6. Le lien signé de la 41.40 (`/blind-test/join`, `/rejoindre?token=`) disparaît. `BLIND_TEST_APP_URL` devient
   l'adresse de base de l'app (sans chemin). L'app gagne `SITE_PUBLIC_URL` (le site vu par le navigateur).

## Tâches

- [x] Site : code à usage unique (entité, migration), émission, échange, accès ; retrait du lien signé.
- [x] Site : page `/blind-test/connexion`, bouton et QR code sur la page de l'événement.
- [x] App : `/connexion`, `/connexion/retour`, client d'échange ; retrait du jeton.
- [x] Tests des deux côtés ; documentation et env.

## Dev Agent Record

- App : PR `archilan-blind-test#4`. Le `state` est vérifié deux fois côté app : contre la session au retour, puis
  contre celui que le site renvoie avec l'identité.
- Site : le code est stocké haché, supprimé dès le premier échange (servi ou non), et les codes expirés sont purgés à
  chaque nouvelle émission. L'échange revérifie les règles d'accès de la 41.40.
- La page `/blind-test/connexion` renvoie vers `/connexion?returnTo=` (query comprise) pour un visiteur déconnecté.
- Fonctionnels du site (`BlindTestTest`) laissés à la CI : Postgres local éteint.
