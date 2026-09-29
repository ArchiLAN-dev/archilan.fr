# Story 40.2: Notifications push du navigateur

**Status:** review
**Epic:** 40 - Déblocage BK notifié et notifications push
**Date:** 2026-09-29

## Story

En tant que membre d'ArchiLAN,
je veux recevoir certaines notifications du site en popup système sur mes appareils, même site fermé, et
pouvoir les couper,
afin d'être prévenu d'un déblocage sans garder un onglet ouvert.

## Contexte

Voir l'epic 40. Rien n'existe côté navigateur. Le standard est le **Web Push** : un service worker reçoit le
message chiffré envoyé par le serveur au service de push du navigateur (Google, Mozilla, Apple), avec une paire
de clés VAPID propre au site. Chaque appareil / navigateur est un abonnement distinct.

## Critères d'acceptation

1. **Activation par appareil** dans l'espace membre (`/compte`, carte « Notifications sur cet appareil ») :
   bouton qui demande la permission du navigateur puis enregistre l'abonnement ; état affiché (activées,
   désactivées, bloquées par le navigateur avec la marche à suivre, non prises en charge) ; bouton pour
   désactiver, qui supprime l'abonnement.
2. **Service worker** servi à la racine du site : affiche la notification (titre, texte, icône ArchiLAN) et, au
   clic, ouvre ou ramène l'onglet sur le lien de la notification.
3. **Abonnements** : entité + migration (utilisateur, endpoint unique, clés `p256dh` et `auth`, navigateur,
   dates) ; routes authentifiées pour enregistrer et supprimer l'abonnement de l'appareil courant ; un
   utilisateur ne voit ni ne supprime que les siens.
4. **Envoi** : quand une notification du site d'un type « poussable » est créée (pour l'instant
   `slot_unblocked`), un job asynchrone l'envoie en Web Push à tous les appareils du destinataire, avec un
   titre, un texte et un lien construits côté serveur. Un abonnement expiré (404 / 410) est supprimé ; une autre
   erreur est journalisée, sans retenter à l'infini. L'envoi ne bloque jamais la création de la notification.
5. **Configuration** : clés VAPID et sujet (`mailto:`) en variables d'environnement, documentées dans
   `envs/api.env.example` ; la clé publique est servie au front par l'API. Sans clés, la carte indique que les
   notifications push ne sont pas disponibles et rien n'est envoyé. Une commande génère une paire de clés.
6. **Limites dites** : sur iPhone / iPad, iOS ne livre le Web Push qu'aux sites installés comme une application
   (manifest, iOS 16.4+). ArchiLAN ne l'est pas encore : la carte dit que les notifications n'y sont pas encore
   disponibles, sans bouton. Rendre le site installable (manifest) est hors périmètre.
7. `composer gates` et `pnpm gates` passent ; Trivy reste vert sur les images `api-web` et `api-worker`.

## Notes techniques

- Bibliothèque PHP : `minishlink/web-push` (chiffrement et signature VAPID). Vérifier ses extensions requises
  (`openssl`, `mbstring`, `curl`, `gmp` ou `bcmath` selon la version) et les ajouter aux deux images API si
  besoin.
- Types poussables : une liste explicite côté API, pour ne pas pousser d'office les futurs types.

## Tasks / Subtasks

- [x] **Task 1** (AC 3, 5) - Entité + migration des abonnements, configuration VAPID, commande de génération de
      clés, route de la clé publique.
- [x] **Task 2** (AC 3) - Routes d'enregistrement et de suppression de l'abonnement, tests fonctionnels.
- [x] **Task 3** (AC 4) - Job d'envoi après création d'une notification poussable, nettoyage des abonnements
      expirés, tests (client Web Push simulé).
- [x] **Task 4** (AC 2) - Service worker (`push`, `notificationclick`).
- [x] **Task 5** (AC 1, 6) - Carte « Notifications sur cet appareil » dans `/compte`, états et tests.
- [x] **Task 6** (AC 7) - Gates, images Docker et scan Trivy.

## Mise en place en prod

1. Générer une paire de clés VAPID (commande de la story) et renseigner les variables dans `envs/api.env`.
2. Redéployer l'API et le worker ; activer les notifications depuis `/compte` sur chaque appareil.

## Dev Agent Record

- **Dépendance** : `minishlink/web-push` ^11 (chiffrement RFC 8291, signature VAPID RFC 8292) ; il amène
  `php-http/discovery` (déjà autorisé, recette `config/packages/http_discovery.yaml`), `web-token/jwt-library`,
  `brick/math`. Envoi par le client HTTP Symfony via `Psr18Client`. `bcmath` ajouté à l'image worker (calculs
  plus rapides) ; `curl`, `mbstring`, `openssl` sont déjà dans PHP.
- **Domaine** (`Community`) : entité `PushSubscription` (table `push_subscription`, endpoint unique), dépôt.
  Migration `Version20260929140000`.
- **Application** : `WebPushConfig` (`WEB_PUSH_VAPID_PUBLIC_KEY`, `WEB_PUSH_VAPID_PRIVATE_KEY`, `WEB_PUSH_SUBJECT`,
  vides = push coupé), `PushMessageFactory` (liste explicite des types poussables : `slot_unblocked` ; texte
  identique à la cloche), `RegisterPushSubscription` (validation, endpoint déjà connu = renouvelé pour le membre
  courant), `RemovePushSubscription` (seulement le sien), `SendWebPushJob` + handler (tous les appareils du
  destinataire ; 404 / 410 = appareil oublié ; autre échec journalisé, jamais retenté). `NotificationService`
  dispatche le job après la notification pour un type poussable.
- **Infrastructure** : `MinishlinkWebPushSender` (TTL 1 h, urgence haute, `topic` dérivé du tag).
- **Présentation** : `GET /api/v1/push/public-key`, `POST` / `DELETE /api/v1/account/push-subscriptions`,
  commande `app:web-push:generate-keys`.
- **Front** : `public/sw.js` (affiche la notification, ouvre ou ramène l'onglet sur le lien, n'ouvre jamais un
  autre site ; pas de cache), en-tête `Cache-Control: no-cache` sur `/sw.js`, page `/compte/notifications`
  (entrée « Notifications » du menu) avec la carte et ses états, `push-support.ts` (logique pure),
  `push-api.ts`.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `PushMessageFactoryTest`, `SendWebPushJobHandlerTest`, `MinishlinkWebPushSenderTest` | classes absentes | verts |
| `PushSubscriptionControllerTest` (fonctionnel : clé, inscription, doublon, refus, suppression, job) | routes absentes | verts |
| `push-support.test.ts`, `push-api.test.ts`, `push-notifications-card.test.tsx` | modules absents | 18 verts |

Gates : `composer gates` (2497 tests), `pnpm gates` (620 tests, build) verts.

### Vérifications

- **Envoi réel sous Linux** (script jetable dans `php:8.5-cli-alpine`, non commité) : clés VAPID et clés d'appareil
  réelles, service de push simulé : `Delivered`, corps chiffré, en-têtes `content-encoding`, `authorization`
  (VAPID), `ttl`, `urgency`, `topic`.
- **Pourquoi pas dans la suite** : chaque envoi crée une clé EC éphémère, que PHP pour Windows ne sait pas créer
  sans `OPENSSL_CONF` au niveau système (lu au chargement de l'extension). Les tests unitaires couvrent donc
  l'interprétation des réponses, pas la cryptographie de la bibliothèque. Linux (CI, images) n'a pas ce problème.
- **Non vérifié** : un vrai navigateur abonné à un vrai service de push (demande l'API avec des clés et le front
  sur la même origine). À faire après déploiement : activer sur `/compte/notifications`, la notification
  « Notifications activées sur cet appareil » s'affiche localement ; puis un vrai déblocage.
