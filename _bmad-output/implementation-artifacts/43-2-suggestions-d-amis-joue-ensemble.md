# Story 43.2: Suggestions d'amis d'après les parties jouées ensemble

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que joueur,
je veux qu'on me propose comme amis les gens avec qui j'ai déjà joué,
afin de remplir ma liste d'amis sans chercher chaque pseudo dans l'annuaire.

## Contexte

Les co-participations existent déjà : slots des runs perso (`SessionSlot::registrationId` = id utilisateur,
`SlotCoPlayer`), slots des événements (via l'inscription, `Registration.userId`). Les hebdos ne comptent pas :
chaque entrée a sa propre session (course solo), il n'y a donc pas de partie partagée. Le graphe d'amis est
vide pour la plupart des membres : c'est le levier principal pour le remplir.

## Critères d'acceptation

1. `FriendSuggestionsQuery` renvoie au viewer les utilisateurs ayant partagé avec lui **au moins une run perso**
   (groupe choisi : une partie suffit) **ou au moins 2 sessions d'événement** (une grosse LAN jouée une fois ne
   suffit pas, c'est le rôle du QR de 43.3), classés par nombre de sessions communes puis par date de la
   dernière. Propriétaires de slot et co-joueurs (`SlotCoPlayer`) comptent.
2. Sont exclus : soi-même, les amis acceptés, les demandes en attente (dans les deux sens), les blocages (dans
   les deux sens), les comptes bannis ou supprimés, et les suggestions ignorées.
3. Chaque suggestion affiche avatar (avec cadre), pseudo, « N parties ensemble », titre de la dernière, un bouton
   « Ajouter » (demande existante) et « Ignorer » (définitif pour cette personne).
4. Affichage : section « Tu as joué avec » dans `/compte/amis`, et un encart de 3 suggestions sur le récap d'une
   partie (`/parties/{sessionId}`, la page de fin de partie depuis la 32.20), visible seulement des participants
   connectés (« Ajoute tes co-joueurs »), limité aux joueurs de cette partie.
5. Une seule requête SQL agrégée (pas de N+1), testée sur un jeu de données mixte.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Application** : `FriendSuggestionsQueryInterface` + `FriendSuggestionDTO`.
- [x] **Infrastructure** : DBAL (union des co-participations, exclusions en `NOT EXISTS`).
- [x] **Domaine/Migration** : table des suggestions ignorées `(user_id, ignored_user_id)`.
- [x] **Présentation** : `GET /community/friend-suggestions`, `POST /community/friend-suggestions/{slug}/ignore`.
- [x] **Front** : section dans `community-friends-panel.tsx`, encart en fin de run.
- [x] Tests fonctionnels (seuil de 2, exclusions, ignorer) et gates.

## Notes

- Seuils validés par Jean le 2026-10-09 : 1 run perso, 2 sessions d'événement ; constantes nommées, ajustables.
- Même agrégat de co-participations que 43.9 (historique commun) : à factoriser.

## Dev Agent Record

### Notes

- Exclusions : toute ligne d'amitié entre les deux (acceptée, en attente ou refusée, dans un sens ou l'autre) ;
  une demande refusée ne revient donc pas en suggestion. Les comptes bannis, suspendus, supprimés ou sans profil
  public tombent avec `CommunityUserDirectoryQueryInterface::cards()`.
- « Ajouter » réutilise la demande d'ami existante (`POST /community/profiles/{slug}/friend-request`).
- Encart du récap : `sessionId` en paramètre ; vide si le lecteur n'a pas joué la partie.
- `FriendIdentity` sorti dans `friend-identity.tsx` (partagé par la liste d'amis et les suggestions).

### File List

- `api/src/Community/Domain/Entity/FriendSuggestionDismissal.php`, `Domain/Repository/FriendSuggestionDismissalRepositoryInterface.php`,
  `Infrastructure/Doctrine/DoctrineFriendSuggestionDismissalRepository.php`, `api/migrations/Version20261009100000.php`
- `api/src/Community/Application/Query/FriendSuggestionsQueryInterface.php`, `FriendSuggestionsQuery.php`,
  `Infrastructure/Dbal/DbalFriendSuggestionsQuery.php`
- `api/src/Community/Application/Command/DismissFriendSuggestion.php` (+ `Outcome`)
- `api/src/Community/Presentation/Controller/CommunityFriendSuggestionsController.php`, `api/config/services.yaml`
- `api/tests/Functional/FriendSuggestionsTest.php`
- `frontend/src/features/community/community-friends-api.ts`, `friend-suggestions.tsx` (+ test), `friend-identity.tsx`,
  `community-friends-panel.tsx`, `frontend/src/features/recap/session-recap-page.tsx`
