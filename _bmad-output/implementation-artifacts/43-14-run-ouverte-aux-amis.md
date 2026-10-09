# Story 43.14: Run ouverte aux amis

**Status:** draft
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.1

## Story

En tant que propriétaire d'une run perso en préparation,
je veux l'ouvrir à tous mes amis,
afin qu'ils la trouvent et la rejoignent sans que j'invite chacun.

## Contexte

Aujourd'hui une run perso n'est accessible que par lien ou (avec 43.1) invitation nominative. Les lobbies
Archipelago (ap-lobby, multiworld.gg) rassemblent un groupe avant la génération : la run en `draft` joue ce rôle.

## Critères d'acceptation

1. Une run `draft` a un réglage d'ouverture : Sur invitation (défaut, comportement actuel) / Amis.
2. « Amis » : la run apparaît dans un bloc « Parties de tes amis » (`/compte/parties` et encart 43.5) pour les
   amis acceptés du propriétaire, qui peuvent la rejoindre directement (même logique que 43.1).
3. Le propriétaire peut fixer un nombre de places (optionnel) ; la run disparaît du bloc quand elle est pleine,
   quitte `draft`, ou repasse « Sur invitation ».
4. Blocages respectés dans les deux sens.
5. Le propriétaire est notifié à chaque arrivée (`run_joined`, cloche seulement).
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Domaine** (`Run`) : `openness`, `seatsWanted` ; changement refusé hors `draft`.
- [ ] **Migration**.
- [ ] **Application** : `FriendsOpenRunsQuery`, commande `JoinOpenRun`.
- [ ] **Présentation/Front** : réglage sur la page de run, bloc « Parties de tes amis », notification.
- [ ] Tests (ouverture x relation, places, blocage, transitions) et gates.

## Notes

- L'ouverture à tous les membres, avec annonce publique, est la story 43.17.
