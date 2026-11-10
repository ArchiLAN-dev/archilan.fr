# Story 52.6 - Départ assisté

**Status:** done
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

- [x] **Task 1** (AC 1, 5) - Demande de départ, notification, droits.
- [x] **Task 2** (AC 2) - Passage planifié sur les départs déclarés.
- [x] **Task 3** (AC 3, 4) - Confirmation et rejet, `release` via le bridge.
- [x] **Task 4** (AC 8) - Front, gates verts, Dev Agent Record.

## Dev Agent Record

**Implémenté le 2026-11-09** (branche empilée sur 52.5).

- **Données** : table `departure_request` (migration `Version20261109120007`) : slot (numéro et nom), origine
  `player` ou `schedule`, statut `open` / `released` / `dismissed`, qui l'a close et quand. Index unique partiel
  `(session_id, slot_name) WHERE status = 'open'` : une seule demande ouverte par slot, posée par
  `INSERT ... ON CONFLICT DO NOTHING` (un double clic ou le passage planifié au même moment ne doublent rien).
- **« Je pars »** (`RequestDeparture`) : `POST /api/v1/sessions/{id}/slots/{n}/departure`, joueur du slot (ou admin).
  Refus : partie sans départs (hebdo) `departure_unavailable`, session pas en cours, monde déjà libéré
  `slot_released`, demande déjà ouverte `departure_already_requested`. Aucune commande envoyée au serveur.
  `GET` même route : `available` et `requestedAt`.
- **Passage planifié** (`RequestDeclaredDepartures`, toutes les 10 min sur le worker async) : session d'événement
  en cours, départ déclaré passé (fin de la dernière plage, `PresenceTimelineQueryInterface::declaredDepartureAt`),
  slot sans client depuis plus de 90 s, monde pas libéré, aucune demande depuis ce départ (ouverte, libérée ou
  écartée) : une seule demande par départ déclaré, même après « il revient ».
- **Décideurs** : en événement, tous les admins sont notifiés ; en partie privée, le propriétaire (un admin peut
  toujours décider). Notification `departure_requested` (cloche + push, réglable), lien vers la session admin ou la
  partie privée.
- **Traitement** (`HandleDeparture`) : `GET /api/v1/sessions/{id}/departures`, `POST .../departures/{d}/release`
  (`!admin /release <nom>` via `SendBridgeCommand`, annoncé dans le feed comme tout release) et `.../dismiss`.
  Bridge en échec : 503 `bridge_unavailable`, demande toujours ouverte. Un joueur ne voit ni ne traite les départs.
- **Front** : bouton « Je pars » (confirmation) sur la page du slot ; « Départs à traiter » sur le détail admin
  d'une session et dans l'aperçu d'une partie privée pour son propriétaire ; notification (texte, lien, préférence).
- **Tests** : `DepartureTest` (9), front `departures.test.tsx` (3).
- **Gates** : API 3733 tests (ParaTest), phpstan, cs-fixer, DDD, rector ; front typecheck, lint, 1489 tests, build.

**À savoir** : le slot n'est marqué libéré côté site (statistiques, story 32.14) pour un nom avec espaces qu'avec
le correctif #1048 (lecture du nom jusqu'à la fin de la ligne) ; la commande envoyée au serveur, elle, est déjà
correcte.
