# Story 41.53: Entrer dans l'animation du blind test avec son compte admin

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-25
**Dépôts :** `archilan.fr` (site) et `ArchiLAN-dev/archilan-blind-test` (app)
**Origine :** Jean le 2026-10-25 : se baser sur les admins d'archilan.fr et leur connexion habituelle pour ouvrir la
console, la régie et l'écran, plutôt que sur un code partagé. `REGIE_TOKEN` reste, facultatif, en secours pour les PC
de la salle.

## Story

En tant qu'admin d'archilan.fr,
je veux ouvrir la console, la régie et l'écran du blind test avec mon compte du site,
afin de ne plus partager de code et que l'accès suive mon rôle d'admin.

## Critères d'acceptation

1. **App, écran de connexion** de `/admin`, `/regie`, `/ecran` : « Se connecter avec archilan.fr » (admins), et, si
   `REGIE_TOKEN` est configuré, le code d'animation en second choix (« pour un PC de la salle »).
2. **Aller** : l'app garde un `state` en session (avec la page à rouvrir) et envoie sur
   `<site>/blind-test/animation?state=…`.
3. **Site** : la page fait se connecter au besoin (retour sur la même page) ; `POST /api/v1/blind-test/animation/codes
   {state}`, réservé aux admins (403 sinon, message clair), crée un code à usage unique (haché, 60 s) sans événement
   et renvoie `<app>/animation/retour?code=…&state=…` (adresse tirée de `BLIND_TEST_APP_URL`).
4. **Échange** : `POST /api/v1/integrations/blind-test/codes/echange` (signé) renvoie pour ce code
   `{userId, eventId: null, displayName, role: "animator", state, exp}` (`exp` à 12 h), après avoir revérifié que la
   personne est toujours admin. Les codes de joueur renvoient `role: "player"` ; rien d'autre ne change pour eux.
5. **App** : un retour d'animation ouvre la session d'animation (jusqu'à `exp` ou « Se déconnecter ») et rouvre la
   page demandée (chemin local seulement) ; un code de joueur n'ouvre jamais l'animation, un code d'animation
   jamais le jeu. La console affiche qui est connecté.

## Tâches

- [x] Site : code d'animation (migration : `event_id` facultatif), émission réservée aux admins, échange avec rôle.
- [x] Site : page `/blind-test/animation`.
- [x] App : connexion d'animation (aller, retour, session), écran de connexion, nom dans la console.
- [x] Tests des deux côtés ; doc.

## Dev Agent Record

- Site : `blind_test_login_code.event_id` facultatif (migration `Version20261025160000`) ; `IssueBlindTestAnimationCode`
  derrière `requireAuthenticatedAdmin` ; l'échange revérifie le rôle admin (`ForbiddenException` sinon) et renvoie
  `role` (`player` pour les codes de joueur) ; page `/blind-test/animation`. `BlindTestTest` : 23 tests verts en
  local sur une base isolée (`test-isolated.sh bt53 --filter BlindTestTest`).
- App : branche `feature/41-53-animation-par-compte-admin`. `AnimationLoginController` (aller, retour, page à rouvrir
  limitée à `/admin`, `/regie`, `/ecran`), `RegieAccess::openAnimator` (session régénérée, 12 h), nom dans la console.
- Vérifié de bout en bout en local : console de l'app, page du site (compte admin déjà connecté), retour sur la console
  « Connecté : masterkafey ». Piège de test local : partir de l'adresse configurée dans `BLIND_TEST_APP_URL`
  (`localhost` et `127.0.0.1` n'ont pas la même session).
