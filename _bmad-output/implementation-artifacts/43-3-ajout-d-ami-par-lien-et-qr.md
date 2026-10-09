# Story 43.3: Ajout d'ami par lien personnel et QR code

**Status:** draft
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que joueur présent à une LAN,
je veux montrer un QR code que mon voisin scanne pour m'ajouter en ami,
afin de garder le contact sans épeler mon pseudo.

## Contexte

ArchiLAN organise des événements physiques : c'est là que les liens se créent, et rien ne les capture
aujourd'hui. La demande d'ami existe (`FriendshipService`), adressée par slug.

## Critères d'acceptation

1. Dans `/compte/amis`, un bloc « Mon lien d'ami » montre un lien personnel (`/ami/{code}`) et son QR code ;
   le code est opaque, distinct du slug, et **régénérable** (l'ancien cesse de fonctionner).
2. Ouvrir le lien connecté affiche la carte du membre et un bouton « Ajouter en ami » ; si l'autre a déjà une
   demande en attente vers moi, c'est une acceptation mutuelle (comportement existant de 30.7).
3. Ouvrir le lien déconnecté renvoie vers la connexion puis revient sur la page.
4. Un blocage dans un sens ou l'autre rend le lien inopérant (même message neutre qu'un code invalide) ; on ne
   peut pas s'ajouter soi-même.
5. Le QR est généré côté front, sans service externe, avec un mode plein écran pour le montrer sur mobile.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Domaine/Migration** : code d'ami sur le profil communautaire (unique, régénérable).
- [ ] **Application/Présentation** : `GET /community/friend-link`, `POST /community/friend-link/regenerate`,
      `GET /community/friend-link/{code}` (carte minimale).
- [ ] **Front** : page `/ami/[code]` (`noindex`, hors sitemap), bloc QR dans `/compte/amis`. Nouvelle
      dépendance `qrcode` (rendu SVG, sans réseau), validée par Jean le 2026-10-09.
- [ ] Tests fonctionnels (code invalide, régénéré, bloqué, soi-même) et gates.
