# Story 43.3: Ajout d'ami par lien personnel et QR code

**Status:** review
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

- [x] **Domaine/Migration** : code d'ami (unique, régénérable), dans sa propre table plutôt que sur le profil
      communautaire (voir Dev Notes).
- [x] **Application/Présentation** : `GET /community/friend-link`, `POST /community/friend-link/regenerate`,
      `GET /community/friend-link/{code}` (carte minimale) et `POST /community/friend-link/{code}/add`.
- [x] **Front** : page `/ami/[code]` (`noindex`, hors sitemap), bloc QR dans `/compte/amis`. Nouvelle
      dépendance `qrcode` (rendu SVG, sans réseau), validée par Jean le 2026-10-09.
- [x] Tests fonctionnels (code invalide, régénéré, bloqué, soi-même) et gates.

## Dev Notes

- **Table dédiée** `community_friend_link` (`FriendLink`, migration `Version20261009120000`) : le profil
  communautaire est créé paresseusement à la première vue, un lien d'ami n'a pas à en dépendre. Un lien par
  membre (unique `user_id`), code unique.
- **Code** : 12 caractères dans un alphabet sans caractères ambigus (`0/o`, `1/l/i`), tiré par
  `FriendLinkService` (le domaine reste pur et reçoit le code). Lecture insensible à la casse.
- **Message neutre** : code inconnu, code régénéré, blocage dans un sens ou l'autre, membre non listable
  (banni, suspendu, sans slug) répondent tous le même 404 `friend_link_invalid`.
- **Ajout** : `POST /community/friend-link/{code}/add` passe par `FriendshipService::requestFriend`, donc une
  demande en attente dans l'autre sens devient une acceptation mutuelle (30.7). Sur son propre lien : 422,
  et la page l'explique sans bouton.
- **Déconnecté** : la page est derrière `RequireAuth`, qui renvoie vers `/connexion?returnTo=/ami/{code}`.
- **QR** : `qrcode.create()` donne la matrice, dessinée en un seul `<path>` SVG, noir sur blanc quel que soit
  le thème. Plein écran via un Dialog Radix blanc. Régénérer demande confirmation.
