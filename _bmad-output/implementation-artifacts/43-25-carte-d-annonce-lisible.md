# Story 43.25: Une carte d'annonce de partie plus lisible

**Status:** review
**Epic:** 43 - Amis
**Date:** 2026-10-11

## Story

En tant que membre ou visiteur,
je veux comprendre une annonce de partie d'un coup d'oeil (quoi, qui, quand, combien de places, quels jeux),
afin de savoir vite si je la rejoins.

## Contexte

Retour de Jean (2026-10-11). La carte publique d'une annonce (page des annonces, encart de Communauté) met l'hôte en
tête et le titre entre guillemets dessous, les jeux en texte brut, les places en « 0 / 4 places » ou « Personne
encore » dans un coin, et les boutons en petites pilules.

## Critères d'acceptation

1. **Titre en tête** (titre de section, sans guillemets), puis « par » l'hôte avec son avatar (lien vers son profil).
   Badge « Ta partie » (43.23) ou « Ami » à droite du titre.
2. **Message** sur quatre lignes au plus.
3. **Faits en pastilles** : la date prévue ; les places restantes (« 3 places libres sur 4 », points pleins pour les
   places prises jusqu'à huit places, « Places illimitées » sinon).
4. **Jeux** en étiquettes, cinq au plus puis « +N ».
5. **Amis déjà dedans** mis en avant avec une icône.
6. **Actions** dans un pied de carte séparé, boutons du site (Rejoindre, Se connecter pour rejoindre, Gérer ma
   partie), « Signaler » discret à droite. Cartes de même hauteur sur deux colonnes à partir de la tablette, page des
   annonces comprise.
7. Gates verts ; tests (places restantes, rendu).

## Tasks / Subtasks

- [x] **Task 1** (AC 1 à 6) - `ListingCard` réécrite, `SeatsChip`, `seatsLeftLabel` ; grille sur deux colonnes.
- [x] **Task 2** (AC 7) - Tests ; gates ; vérification en local (visiteur).

## Dev Agent Record

- `run-listings.tsx` : `ListingCard` (titre `h3`, hôte en `MemberAvatar` 24 px avec marge pour les cadres),
  `SeatsChip`, `seatsLeftLabel`, `MAX_GAMES` ; `FriendIdentity` et `seatsLabel` ne servent plus ici.
