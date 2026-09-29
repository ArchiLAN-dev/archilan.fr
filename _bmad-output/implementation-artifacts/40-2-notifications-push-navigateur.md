# Story 40.2: Notifications push du navigateur

**Status:** ready-for-dev
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
6. **Limites dites** : sur iPhone / iPad, le Web Push n'existe que pour un site ajouté à l'écran d'accueil
   (iOS 16.4+) ; la carte le signale. Rendre le site installable (manifest) est hors périmètre.
7. `composer gates` et `pnpm gates` passent ; Trivy reste vert sur les images `api-web` et `api-worker`.

## Notes techniques

- Bibliothèque PHP : `minishlink/web-push` (chiffrement et signature VAPID). Vérifier ses extensions requises
  (`openssl`, `mbstring`, `curl`, `gmp` ou `bcmath` selon la version) et les ajouter aux deux images API si
  besoin.
- Types poussables : une liste explicite côté API, pour ne pas pousser d'office les futurs types.

## Tasks / Subtasks

- [ ] **Task 1** (AC 3, 5) - Entité + migration des abonnements, configuration VAPID, commande de génération de
      clés, route de la clé publique.
- [ ] **Task 2** (AC 3) - Routes d'enregistrement et de suppression de l'abonnement, tests fonctionnels.
- [ ] **Task 3** (AC 4) - Job d'envoi après création d'une notification poussable, nettoyage des abonnements
      expirés, tests (client Web Push simulé).
- [ ] **Task 4** (AC 2) - Service worker (`push`, `notificationclick`).
- [ ] **Task 5** (AC 1, 6) - Carte « Notifications sur cet appareil » dans `/compte`, états et tests.
- [ ] **Task 6** (AC 7) - Gates, images Docker et scan Trivy.

## Mise en place en prod

1. Générer une paire de clés VAPID (commande de la story) et renseigner les variables dans `envs/api.env`.
2. Redéployer l'API et le worker ; activer les notifications depuis `/compte` sur chaque appareil.
