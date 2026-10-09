# Story 43.2: Suggestions d'amis d'après les parties jouées ensemble

**Status:** draft
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

1. `FriendSuggestionsQuery` renvoie au viewer les utilisateurs ayant partagé **au moins 2 sessions** avec lui
   (run perso ou session d'événement), classés par nombre de sessions communes puis par date de
   la dernière.
2. Sont exclus : soi-même, les amis acceptés, les demandes en attente (dans les deux sens), les blocages (dans
   les deux sens), les comptes bannis ou supprimés, et les suggestions ignorées.
3. Chaque suggestion affiche avatar (avec cadre), pseudo, « N parties ensemble », titre de la dernière, un bouton
   « Ajouter » (demande existante) et « Ignorer » (définitif pour cette personne).
4. Affichage : section « Tu as joué avec » dans `/compte/amis`, et un encart de 3 suggestions sur la page d'une
   run perso terminée (« Ajoute tes co-joueurs »).
5. Une seule requête SQL agrégée (pas de N+1), testée sur un jeu de données mixte.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Application** : `FriendSuggestionsQueryInterface` + `FriendSuggestionDTO`.
- [ ] **Infrastructure** : DBAL (union des co-participations, exclusions en `NOT EXISTS`).
- [ ] **Domaine/Migration** : table des suggestions ignorées `(user_id, ignored_user_id)`.
- [ ] **Présentation** : `GET /community/friend-suggestions`, `POST /community/friend-suggestions/{slug}/ignore`.
- [ ] **Front** : section dans `community-friends-panel.tsx`, encart en fin de run.
- [ ] Tests fonctionnels (seuil de 2, exclusions, ignorer) et gates.

## Notes

- Le seuil de 2 évite de suggérer tous les inscrits d'une grosse LAN ; constante nommée, ajustable.
- Même agrégat de co-participations que 43.9 (historique commun) : à factoriser.
