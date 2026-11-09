# Story 5.10: L'admin inscrit un joueur de bout en bout

**Status:** review
**Epic:** 5 - Administration des inscriptions
**Date:** 2026-11-09
**Origine :** demande de Jean le 2026-11-09 : « Faut qu'un admin puisse faire une inscription de bout en bout si
nécessaire en bypassant les restrictions (juste des warning si jamais on dépasse certaines choses) ».

## Story

En tant qu'admin d'un événement,
je veux créer, compléter et confirmer l'inscription d'un joueur à sa place, même hors des règles imposées aux joueurs,
afin de régulariser un cas particulier (retardataire, souci technique, invité, inscription libérée à la clôture) sans
toucher à la base à la main.

## Contexte (existant)

- **Réservation joueur** (`ReserveRegistration::reserve`) : refuse si
  - email non vérifié (`email_not_verified`) ;
  - événement privé sans accès (`private_event`), en cours ou terminé (`event_not_open`) ;
  - hors fenêtre `registrationOpensAt` / `registrationClosesAt` (`registration_not_open_yet`, `registration_closed`) ;
  - complet (`capacity_full`, compte des confirmées sous verrou exclusif de l'event).
  Une inscription annulée est réactivée (`Registration::reactivate`), jamais dupliquée (index unique event + user).
- **Confirmation joueur** (`RegistrationSubmission::submit`) : au moins un jeu (`games_required`), puis
  `Registration::confirm` (`submittedAt`) et mail `RegistrationConfirmationMessage`. Sans `submittedAt`, pas d'accès
  à la connexion de session ni au patch (`PlayerSessionConnection`, `RegistrationPatchQuery`).
- **Clôture** (`ProcessUnconfirmedRegistrationsHandler`) : rappel avant `registrationClosesAt`, puis libération
  (annulation + mail) des inscriptions non confirmées.
- **Admin aujourd'hui** (stories 5.4, 5.9) : modifie les jeux (`AdminRegistrationModification::update`) et le YAML
  (`AdminSlotYamlModification::save`) d'une inscription **active** seulement, sans verrou de date ; refuse au-delà de
  `gameSelectionMaxPerRegistrant` et pour un jeu absent de `gameSelectionConfig`. Annule (`AdminRegistrationCancellation`).
  Ne peut ni créer, ni réactiver, ni confirmer.

## Décisions (choix par défaut validés par Jean le 2026-11-09)

1. **Règle contournable = avertissement, jamais blocage.** Les règles joueur ci-dessous deviennent des avertissements
   pour l'admin :
   - `registration_not_open_yet`, `registration_closed` ;
   - `event_not_published` (événement en brouillon), `event_in_progress` (événement en cours) ;
   - `capacity_full` (l'inscription dépasse la capacité) ;
   - `email_not_verified` ;
   - `private_event` (le joueur n'a pas accès à l'événement privé) ;
   - `max_games_exceeded` (plus de jeux que `gameSelectionMaxPerRegistrant`) ;
   - `game_not_in_event` (jeu hors de la sélection de l'événement) ;
   - `incomplete_yaml` (confirmation avec un slot sans YAML joueur).
2. **Ce qui reste bloquant**, parce que la suite ne pourrait pas fonctionner :
   - événement introuvable ou terminé (`completed`) ;
   - joueur introuvable, supprimé ou banni ;
   - jeu introuvable ou sans apworld prêt pour le YAML ;
   - YAML invalide (nom de slot, `game:`), déjà bloquant en 5.9 ;
   - confirmation sans aucun jeu (`games_required`).
3. **Flux d'avertissement en deux temps.** Chaque action admin de ce flux accepte `acknowledgeWarnings: bool`.
   Sans lui, s'il y a des avertissements, l'API ne fait rien et répond `409 warnings_to_acknowledge` avec la liste
   `{code, message}`. Le front l'affiche dans une confirmation (« Inscrire quand même »), puis rejoue l'appel avec
   `acknowledgeWarnings: true`. Sans avertissement, l'action passe du premier coup.
4. **Traçabilité.** Chaque action écrit une entrée d'audit (`admin.registrations.create`, `.reactivate`, `.confirm`)
   avec l'admin et la liste des avertissements acquittés. Une inscription créée par un admin le dit dans le détail
   admin (« créée par un admin », « confirmée par un admin »), pas côté joueur.
5. **Mails.** Le joueur reçoit le mail de confirmation habituel quand l'admin confirme (case cochée par défaut, que
   l'admin peut décocher). Pas de mail à la création seule.
6. **Pas de nouveau statut.** On réutilise `reserved` / `cancelled` et `submittedAt` ; seule la trace d'audit et un
   champ `createdByAdminId` / `confirmedByAdminId` (nullable, migration) distinguent l'origine admin.

## Critères d'acceptation

1. **Créer.** Depuis la liste admin des inscriptions d'un événement, un bouton « Inscrire un joueur » ouvre une
   recherche de membre (nom, pseudo, email). L'admin choisit un joueur et crée l'inscription
   (`POST /api/v1/admin/events/{eventId}/registrations`, `{userId, acknowledgeWarnings}`).
   - Si le joueur a déjà une inscription active : `409 already_registered`, avec un lien vers elle.
   - Si elle est annulée : elle est réactivée (même ligne, comme côté joueur) au lieu d'être dupliquée.
   - Le compteur de places temps réel et le flux admin temps réel (story 5.7) sont mis à jour comme pour une
     inscription joueur. Un compteur de places restantes ne descend jamais sous 0 à l'affichage.
2. **Réactiver.** Sur le détail d'une inscription annulée (y compris libérée à la clôture), un bouton « Réactiver »
   la remet en `reserved` (`POST .../registrations/{registrationId}/reactivate`), avec les mêmes avertissements
   (capacité, fenêtre). Les jeux et YAML qu'elle portait sont conservés.
3. **Jeux et YAML.** Une fois créée ou réactivée, l'inscription s'édite avec les écrans de la 5.9. Dans ce flux
   admin, `max_games_exceeded` et `game_not_in_event` deviennent des avertissements à acquitter au lieu d'erreurs
   (`PATCH` existant + `acknowledgeWarnings`).
4. **Confirmer.** Sur le détail d'une inscription active non confirmée, un bouton « Confirmer pour le joueur »
   (`POST .../registrations/{registrationId}/confirm`, `{sendEmail, acknowledgeWarnings}`) pose `submittedAt`.
   Avertissement `incomplete_yaml` si un slot n'a pas de YAML joueur ; `games_required` reste bloquant.
   Le mail part seulement si `sendEmail` vaut true, après le commit.
5. **Bloquants.** Chaque cas de la décision 2 renvoie l'erreur typée existante (404, 409 ou 422), jamais un
   avertissement, et rien n'est écrit.
6. **Acquittement.** Pour chaque action :
   - sans `acknowledgeWarnings` et avec au moins un avertissement : `409 warnings_to_acknowledge`, la liste complète,
     aucune écriture ;
   - avec `acknowledgeWarnings: true` : l'action passe ;
   - sans avertissement : l'action passe directement.
   Le front montre la liste en français, puis rejoue l'appel sur confirmation.
7. **Audit et origine.** Chaque création, réactivation ou confirmation admin est auditée avec la liste des
   avertissements acquittés. Le détail admin affiche « créée par un admin » / « confirmée par un admin ».
8. **Joueurs inchangés.** Les règles côté joueur ne bougent pas : tous les tests existants de réservation,
   confirmation et sélection de jeux restent verts sans modification.
9. **Droits.** Toutes les nouvelles routes exigent un admin (`requireAuthenticatedAdmin`) ; un non-admin reçoit 403.

## Tâches

- [x] Domaine : `createdByAdminId`, `confirmedByAdminId` sur `Registration` + migration réversible.
- [x] Application :
  - `AdminRegistrationCreation` (création ou réactivation, verrou exclusif de l'event comme `ReserveRegistration`) ;
  - `AdminRegistrationConfirmation` ;
  - avertissements dans `AdminRegistrationModification` ;
  - un type commun pour la liste d'avertissements et l'échec `warnings_to_acknowledge`.
- [x] Présentation : routes `POST registrations`, `POST .../reactivate`, `POST .../confirm` ; recherche de membre
      (réutiliser l'annuaire admin existant s'il convient).
- [x] Front :
  - « Inscrire un joueur » sur la liste ;
  - « Réactiver » et « Confirmer pour le joueur » sur le détail ;
  - dialogue d'avertissements, mentions d'origine admin.
- [x] Tests fonctionnels :
  - chaque avertissement, levé puis acquitté ;
  - chaque bloquant ;
  - réactivation d'une inscription libérée à la clôture ;
  - mail envoyé ou non ;
  - 403 pour un non-admin ;
  - compteur de places.
- [x] Tests front : dialogue d'avertissements, rejeu avec acquittement.
- [x] Gates verts.

## Questions ouvertes pour Jean

- Faut-il aussi un avertissement (plutôt qu'un blocage) pour un **événement terminé**, pour régulariser après coup ?
  Par défaut : bloquant.
- Une inscription confirmée par l'admin doit-elle compter dans les **succès / stats de participation** comme une
  inscription normale ? Par défaut : oui, c'est une inscription comme une autre.
- Les **pelles / frais d'inscription** éventuels : rien n'est débité ni remboursé par ce flux. À confirmer.

## Dev Agent Record

### Implémentation

- Domaine : `Registration` gagne `createdByAdminId` et `confirmedByAdminId` (migration `Version20261109120000`),
  `restoreByAdmin` (remet une inscription annulée telle qu'elle était, jeux, YAML et confirmation compris, à la
  différence de `reactivate` côté joueur qui repart de zéro) et `confirmByAdmin`.
- Application :
  - `AdminRegistrationCreation` (`create`, `reactivate`) reprend le verrou exclusif de l'event de
    `ReserveRegistration`, puis publie le compteur de places, le flux admin et l'alerte de capacité après le commit ;
  - `AdminRegistrationConfirmation` ;
  - avertissements `max_games_exceeded` et `game_not_in_event` dans `AdminRegistrationModification` (un jeu
    inexistant reste une erreur 422) ;
  - `AdminRegistrationWarning` (enum code + message FR) et `WarningsToAcknowledgeException` (409, `details.warnings`) ;
  - le mail de confirmation est extrait dans `RegistrationConfirmationNotice`, partagé avec `RegistrationSubmission`
    (comportement joueur inchangé).
- Présentation : `POST .../registrations`, `POST .../{id}/reactivate`, `POST .../{id}/confirm` ; le détail admin expose
  `createdByAdmin` / `confirmedByAdmin`.
- Front :
  - `useAcknowledgedAction` + `AdminWarningsDialog` (`admin-warnings.tsx`) ;
  - bouton « Inscrire un joueur » sur la liste (`admin-register-member.tsx`), qui ouvre ensuite la fiche ;
  - « Réactiver l'inscription » / « Confirmer pour le joueur » avec case du mail (`admin-registration-actions.tsx`) ;
  - badges d'origine admin ;
  - l'éditeur de jeux ne bloque plus au plafond (avertissement à l'enregistrement) ;
  - le sélecteur de membre des adhésions devient `admin-user-combobox.tsx`, partagé.

### Écarts assumés

- Le sélecteur de jeux de l'admin ne propose toujours que les jeux actifs de l'événement : `game_not_in_event`
  couvre surtout un jeu retiré de l'événement après coup. Ajouter un jeu hors événement depuis l'écran demanderait un
  sélecteur de catalogue, hors périmètre.
- La réactivation n'écrit pas `createdByAdminId` : l'inscription reste celle du joueur, le journal d'audit garde l'admin.

### Tests

- `AdminRegistrationOnBehalfTest` (15 tests) :
  - chaque avertissement levé puis acquitté ;
  - bloquants (événement terminé, joueur inconnu ou banni, sans jeu) ;
  - réactivation d'une inscription libérée à la clôture avec ses jeux ;
  - mail envoyé ou non ;
  - 403 ;
  - origine admin dans le détail.
- Les suites joueur (`RegistrationSubmitTest`) et admin existantes restent vertes sans modification.
- Front : `admin-registration-on-behalf.test.tsx` ; `admin-registration-edit.test.tsx` adapté au nouveau résultat
  (`kind`) et à l'acquittement.
