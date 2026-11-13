# Story 53.9: Tous les slots de la partie sur la page session d'un inscrit

**Status:** ready-for-dev (AC 1 livré en hotfix v0.66.2)
**Epic:** 53 - Mon event (espace inscrit)
**Date:** 2026-11-13

## Story

En tant qu'inscrit à un événement,
je veux voir mes slots en tête de la progression des joueurs et pouvoir ouvrir la fiche de n'importe quel slot de la partie,
afin de suivre la partie entière (indices, emplacements, suivi « faisable ») et pas seulement mes jeux.

## Contexte

Page `/evenements/{slug}/inscription/{registrationId}/session` (`SessionConnectionGate`). La grille
« Progression des joueurs » (`components/session/PlayerProgressGrid.tsx`) trie les slots par index ; sur une partie
de 110 slots, un joueur cherche ses jeux. Depuis la 41.9, seules les cartes de ses slots ouvrent une fiche
(`slotHref` dans `session-connection-gate.tsx`), les autres restent des cartes.

Côté API (`Sessions/Presentation/Controller/PlayerStateController.php`), la lecture des indices
(`GET .../slots/{slotIndex}/hints`), des emplacements d'items (`.../item-locations`) et du suivi
(`.../reachable`) est réservée au propriétaire du slot ou à un admin (`ownsSlot`). Le jeton Mercure du suivi
(`reachable-token`) est déjà accordé à tout inscrit de la session (`isAuthorized`).

**Choix de Jean (2026-11-13) :** la fiche d'un autre slot montre tout, comme la sienne : indices, emplacements
d'items et suivi « faisable ». Seules les écritures restent au propriétaire.

## Critères d'acceptation

1. **Ordre** : dans « Progression des joueurs » de la page session d'un inscrit, ses slots viennent en tête (ordre
   des index entre eux), puis les autres dans l'ordre actuel. Ses cartes sont repérables (badge « Toi » ou
   équivalent). Les autres usages de `PlayerProgressGrid` (run privée, admin) ne changent pas d'ordre.
2. **Toutes les cartes cliquables** : chaque carte ouvre
   `/evenements/{slug}/inscription/{registrationId}/session/slots/{slotIndex}`.
3. **Fiche d'un autre slot** : checks, items, sphères, indices et suivi « faisable » visibles comme sur la sienne.
   Aucun contrôle d'écriture : pas d'achat d'indice (points ou pelles), pas de priorité d'indice, pas de prime,
   pas de panneau du Passeur, pas de départ. Un bandeau dit à qui est le slot (« Slot de {joueur} - lecture seule »).
4. **Sélecteur de slots** de la fiche : propose tous les slots de la partie, ceux de l'inscrit en tête ; le lien de
   retour mène toujours à sa page de session.
5. **API, lecture** : `hints`, `item-locations` et `reachable` (GET) acceptent tout utilisateur autorisé sur la
   session (`isUserAuthorizedForSession` : inscrit de l'événement de la session) en plus du propriétaire et de
   l'admin. Un utilisateur non inscrit reste à 403.
6. **API, écriture inchangée** : `hints/request`, `hints/request-item`, `PATCH hints/{locationId}`, pelles
   (`PelleHintController`), primes (`ItemBountyController`), Passeur (`PasseurController`), départ
   (`DepartureController`) restent réservés au propriétaire ou à l'admin. Tests fonctionnels qui le prouvent pour un
   inscrit sur le slot d'un autre.
7. **Runs privées** : comportement inchangé (mêmes droits, mêmes liens). Si l'élargissement de lecture passe par une
   règle commune, il ne s'applique qu'aux sessions d'événement.
8. **Visibilité des sphères** (`spheresVisibility`, story 27.13) : la règle actuelle s'applique aussi à la fiche
   d'un autre slot (pas de contournement par ce chemin).
9. Gates : `composer gates` et `pnpm gates` verts.

## Tasks

- [ ] Task 1 (AC 5, 6, 7) - `PlayerStateController` : un garde de lecture (propriétaire, admin ou inscrit de
  l'événement) pour les trois GET ; les écritures gardent `ownsSlot`. Tests fonctionnels lecture et écriture.
- [ ] Task 2 (AC 1, 2) - (ordre et badge « Toi » livrés en v0.66.2 : `ownSlotNames`) `PlayerProgressGrid` : option d'ordre « mes slots d'abord » (noms ou index fournis par
  l'appelant) et marque visuelle ; `slotHref` toujours défini sur la page session.
- [ ] Task 3 (AC 3, 4) - fiche de slot d'événement (`EventSlotDetailPage`) : mode lecture seule quand le slot
  n'est pas à l'inscrit (masque achats, priorités, primes, Passeur, départ ; bandeau) ; sélecteur sur tous les slots.
- [ ] Task 4 (AC 8, 9) - vérifier la visibilité des sphères, tests front, gates.

## Dev Notes

- Le propriétaire d'un slot se lit via `/sessions/{id}/slot-owners` (déjà utilisé par la fiche).
- Sur une grande partie, ouvrir le suivi d'un autre slot ne lance pas de calcul en plus : le démon de session
  calcule déjà tous les slots (story 17.28).
