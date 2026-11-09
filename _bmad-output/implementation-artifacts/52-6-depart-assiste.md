# Story 52.6 - Départ assisté

**Status:** ready-for-dev
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-52-story-6-depart-assiste` (depuis `develop`)
**Dépend de:** 52.2 pour l'heure de départ déclarée en événement. Livrable avant le reste de l'epic.

## Story

En tant que joueur qui quitte l'événement ou la partie,
je veux prévenir l'orga que je pars,
afin qu'elle libère mon monde et que personne ne reste bloqué par moi.

En tant qu'orga,
je veux qu'on me propose de faire le `release` d'un joueur parti,
afin de le faire en un clic, sans oubli.

## Contexte (cartographie du 2026-11-09)

- Commandes admin vers le serveur : `SendBridgeCommand`, `POST /commands` du bridge (`send_admin_command`).
- Le bridge lit déjà les `release` et le site exclut des statistiques un slot relâché sans avoir atteint son but
  (story 32.14).
- Notifications : `Notifier` (post-commit), push (`PushMessageFactory`).

## Décisions (validées par Jean le 2026-11-09)

1. **Le joueur ne lance pas son `release`.** Depuis sa page de partie, un bouton « Je pars » prévient les admins de
   l'événement (le propriétaire en partie privée) ; aucun `release` n'est envoyé par le joueur.
2. **À l'heure de départ déclarée** (52.2), si le slot n'a plus de client connecté : notification aux admins
   « Bob est parti, libérer son monde ? ».
3. **Confirmation en un clic** par l'admin (ou le propriétaire en partie privée) : `/release <joueur>` via le bridge.
   Jamais automatique.
4. Une demande sans suite reste visible dans une liste « Départs à traiter » de la session, jusqu'au `release` ou à
   son rejet (« il revient »).
5. Le `release` est annoncé dans le feed comme aujourd'hui.

## Critères d'acceptation

1. « Je pars » crée une demande, notifie les admins (ou le propriétaire), et ne peut être fait qu'une fois tant
   qu'elle est ouverte.
2. Un départ déclaré passé, slot déconnecté, crée la même demande (passage planifié, sans doublon).
3. L'admin confirme : `release` envoyé, demande close ; échec du bridge : demande toujours ouverte, message clair.
4. L'admin rejette : demande close sans `release`.
5. Un joueur ne peut ni confirmer ni lancer un `release`.

## Tests et gates

6. Fonctionnels : demande joueur, demande planifiée, confirmation, rejet, droits, échec du bridge.
7. Front : bouton « Je pars », liste « Départs à traiter », confirmation.
8. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1, 5) - Demande de départ, notification, droits.
- [ ] **Task 2** (AC 2) - Passage planifié sur les départs déclarés.
- [ ] **Task 3** (AC 3, 4) - Confirmation et rejet, `release` via le bridge.
- [ ] **Task 4** (AC 8) - Front, gates verts, Dev Agent Record.
