# Story 11.10 - Co-joueurs d'un slot de session d'événement

**Status:** backlog (à affiner avec Jean avant développement)
**Epic:** 11 - Session Management UX/UI Overhaul (`_bmad-output/planning-artifacts/epics/epic-11-session-management-uxui-overhaul.md`)
**Date:** 2026-11-10
**Dépend de:** 11.9 (choix des slots d'une session), 16.17 (co-joueurs des parties perso).

## Story

En tant qu'admin d'un événement,
je veux ajouter des co-joueurs à un slot d'une session d'événement,
afin qu'un inscrit qui n'a pas son propre slot dans cette session y ait quand même accès (nom de slot, connexion,
suivi) en jouant sur le slot d'un autre.

## Contexte

Demande de Jean le 2026-11-10, juste après la 11.9 : avec la sélection des slots, des inscrits à l'événement peuvent
ne pas être dans une session créée. Ils doivent pouvoir y entrer comme co-joueurs d'un slot.

Existant à réutiliser : `Sessions\Domain\Entity\SlotCoPlayer`, `SlotCoPlayerRepositoryInterface` et
`SlotCoPlayerRoster` (story 16.17), aujourd'hui pilotés par `PersonalRuns\Application\Service\RunSlotCoPlayers` (le
propriétaire de la partie remplace la liste entière ; le co-joueur ne touche jamais au YAML du slot).

## Points à trancher avec Jean

1. Qui ajoute les co-joueurs : l'admin seul, ou aussi le titulaire du slot ?
2. Qui est éligible : les inscrits de l'événement seulement (statut confirmé ?), ou tout membre ?
3. Un inscrit déjà titulaire d'un slot de la session peut-il aussi être co-joueur d'un autre slot ?
4. Ce que voit le co-joueur : nom de slot et infos de connexion (comme le titulaire), page de la session, suivi,
   notifications (BK, Passeur, départs) ?
5. Effets annexes : succès et statistiques (le co-joueur compte-t-il comme ayant joué ?), Passeur (achat par un
   co-joueur), départs assistés.

## Critères d'acceptation (provisoires)

1. Sur une session d'événement, l'admin ajoute ou retire des co-joueurs à un slot parmi les inscrits de l'événement.
2. Un co-joueur absent de la session par ses propres slots y a accès comme le titulaire pour ce slot.
3. Le YAML du slot reste celui du titulaire.
4. Retirer le co-joueur lui retire l'accès.

## Dev Agent Record

À compléter.
