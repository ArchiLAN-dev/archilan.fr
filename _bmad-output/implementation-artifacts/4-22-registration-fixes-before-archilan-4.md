# Story 4.22: Correctifs de l'inscription avant l'ArchiLAN 4

**Status:** review
**Epic:** 4 - Inscription aux événements
**Date:** 2026-10-12

## Story

En tant qu'organisateur de l'ArchiLAN 4 (inscriptions envisagées du 19 au 31 octobre 2026),
je veux que le parcours d'inscription et l'admin de l'événement fonctionnent sans piège,
afin que les membres s'inscrivent vraiment et que l'équipe puisse gérer l'événement sans perdre de données.

## Contexte

Revue des événements du 2026-10-12 (front public, admin, règles API). Sept bugs confirmés dans le code, sans décision
produit à prendre. Les sujets qui demandent un arbitrage (paiement HelloAsso et inscription, événement privé et
billetterie, réservations jamais confirmées, statuts « Complet » / « Inscriptions fermées ») font l'objet d'une story
suivante.

## Critères d'acceptation

1. **Lien HelloAsso conservé et éditable.**
   - Le formulaire admin d'un événement affiche un champ « Formulaire HelloAsso (slug) », pré-rempli avec la valeur
     actuelle, facultatif, 120 caractères au plus.
   - L'API ne modifie le slug que si la clé `helloassoFormSlug` est présente dans la requête : un enregistrement qui ne
     l'envoie pas le laisse intact. Une chaîne vide le retire. Au-delà de 120 caractères, erreur de champ lisible.
   - Le type front de l'événement admin (`admin-events-api.ts`) et `EventFormInput` portent le slug ; la page
     d'édition l'envoie.
   - Aujourd'hui `AdminEventDrafts` (création et `update`, ligne ~139) remet le slug à `null` à chaque enregistrement,
     et aucune interface ne permet de le poser.
2. **Se réinscrire après une annulation.**
   - Un membre qui a annulé son inscription et s'inscrit de nouveau (fenêtre ouverte, places disponibles) retrouve
     une inscription réservée : la ligne annulée est réactivée (statut `reserved`, `submittedAt` et sélection de jeux
     remis à zéro, `createdAt` et `updatedAt` à l'heure de la réinscription pour que l'ordre d'arrivée reste juste),
     sans nouvelle ligne. Son identifiant ne change pas.
   - La page de l'événement propose de nouveau « S'inscrire » à un membre dont l'inscription est annulée. Aujourd'hui
     `fetchMyRegistrationCta` (`events-api.ts`) ignore le statut renvoyé par `my-registration` et affiche « Modifier
     mon inscription » vers le récap d'une inscription annulée.
   - Aujourd'hui `ReserveRegistration` insère une deuxième ligne, refusée par l'index unique
     `uniq_event_registrations_event_user`, et le membre reçoit une erreur 500.
   - La capacité, le compteur temps réel et la notification admin se comportent comme pour une première inscription.
3. **Modification admin sans perte de YAML.**
   - Quand un admin modifie les jeux d'une inscription, chaque emplacement conservé garde son YAML et son empreinte
     d'apworld. Un jeu ajouté reçoit le YAML par défaut du jeu, comme dans le parcours membre.
   - Aujourd'hui `AdminRegistrationModification::diffSlots` ne renvoie que `slotId` et `gameId`, et tous les YAML du
     joueur sont effacés.
   - Note : ce PATCH admin n'a pas encore d'écran ; on corrige l'API pour qu'il soit sûr quand l'écran viendra
     (story suivante).
4. **Réservé n'est pas confirmé.**
   - Après la réservation, la page d'inscription dit « Place réservée » et explique qu'il reste à choisir ses jeux
     puis à confirmer l'inscription au récapitulatif. Elle ne dit plus « Ta place est confirmée ».
   - Le récapitulatif indique si l'inscription est déjà confirmée ou encore à confirmer. L'API de lecture de
     l'inscription utilisée par le récap expose `submittedAt` (elle ne le renvoie pas aujourd'hui).
5. **Sélection de jeux désactivée.**
   - Quand l'événement n'a pas de sélection de jeux (`gameSelectionEnabled = false`), le membre peut aller au
     récapitulatif et confirmer sans choisir de jeu (l'API l'accepte déjà).
   - Aujourd'hui le bouton exige au moins un jeu, et l'inscription ne peut jamais être confirmée.
6. **Email non vérifié expliqué.**
   - L'éligibilité (`RegistrationEligibility`) renvoie la raison `email_not_verified` quand l'email du membre n'est pas
     vérifié, après les raisons liées à l'événement : la page l'affiche avant le clic, avec un bouton pour renvoyer
     l'email de confirmation (`resendEmailConfirmation`).
   - Si la réservation est quand même refusée pour cette raison (403 `email_not_verified`), même message et même
     bouton.
   - Les autres refus (422 `not_eligible`, etc.) affichent le message renvoyé par l'API au lieu de « Une erreur est
     survenue, réessaie ».
7. **Email de confirmation à l'heure de Paris.**
   - La date de l'événement dans l'email de confirmation est convertie en Europe/Paris et formatée « 20/11/2026 à
     14h00 ».
   - Aujourd'hui le format `d/m/Y a H\hi` imprime « pm » à la place de « à », et l'heure n'est pas convertie.
8. Tests (slug conservé et retiré, réinscription après annulation, YAML conservé à la modification admin, confirmation
   sans jeu, format de l'email, messages d'erreur côté front) et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - API : `helloassoFormSlug` modifié seulement si présent, longueur validée ; front : champ dans
  `admin-event-form.tsx`, `EventFormInput` et type admin ; tests.
- [x] **Task 2** (AC 2) - `Registration::reactivate($now)` (domaine pur, refusé si l'inscription n'est pas annulée) et
  branchement dans `ReserveRegistration` ; CTA de la page événement selon le statut ; tests unitaire, fonctionnel et
  front.
- [x] **Task 3** (AC 3) - `AdminRegistrationModification::diffSlots` reporte `playerYaml` et `apworldHash`, YAML par
  défaut pour un jeu ajouté (`getDefaultYaml` / `getApworldHash` du jeu, comme `RegistrationGameSelection::diffSlots`) ;
  test fonctionnel du PATCH.
- [x] **Task 4** (AC 4, 5, 6) - API : `submittedAt` dans la lecture de l'inscription, raison `email_not_verified` dans
  l'éligibilité. Front : textes du panneau de réservation, état confirmé sur le récap, `canContinue` sans jeu quand la
  sélection est désactivée, messages d'erreur de la réservation et renvoi de l'email ; tests.
- [x] **Task 5** (AC 7) - Format et fuseau de la date dans `RegistrationSubmission` ; test.
- [x] **Task 6** (AC 8) - Gates.

## Revue de la story (2026-10-12)

- Ajouté : le CTA de la page événement après une annulation (AC 2), `submittedAt` côté API pour le récap (AC 4), la
  raison `email_not_verified` dans l'éligibilité (AC 6), le type admin du slug (AC 1).
- Vérifié : le format `a H\hi` n'existe qu'à un endroit (`RegistrationSubmission.php:77`) ; l'annulation ne vide pas
  `submittedAt`, d'où la remise à zéro explicite à la réactivation.

## Hors périmètre (story suivante, après décisions)

- Lien entre paiement HelloAsso et inscription, billetterie sur un événement privé ou complet.
- Expiration ou relance des réservations jamais confirmées.
- Statuts « Complet », « Inscriptions fermées », « En cours » sur les cartes et la page, date d'ouverture affichée.
- Améliorations admin (galerie, sélection de jeux, export, dépublication) et textes mineurs.

## Dev Agent Record

- AC 1 : `AdminEventDrafts` ne relie le formulaire HelloAsso que si `helloassoFormSlug` est envoyé, refuse plus de 120
  caractères ; champ « Formulaire HelloAsso (slug) » avec aide dans `admin-event-form.tsx`, type et garde dans
  `admin-events-api.ts`.
- AC 2 : `Registration::reactivate` (statut, jeux, `submittedAt`, `createdAt`) ; `ReserveRegistration` réactive la
  ligne annulée ; `fetchMyRegistrationCta` traite une inscription annulée comme absente.
- AC 3 : `AdminRegistrationModification::diffSlots` reporte `playerYaml` et `apworldHash`, YAML par défaut du jeu pour
  un jeu ajouté (dépendance `GameRepositoryInterface`).
- AC 4 : textes « Place réservée » et « Tu es déjà inscrit » ; `submittedAt` dans `GET .../game-selection` ; bandeau
  confirmée / à confirmer sur le récap, bouton « Confirmer » masqué une fois confirmée.
- AC 5 : `canContinueToRecap` (sans sélection de jeux, on passe au récap) ; bouton de sauvegarde masqué dans ce cas.
- AC 6 : raison `email_not_verified` dans `RegistrationEligibility` (après les raisons de l'événement) ; la page
  d'inscription affiche `EmailVerificationBanner` (renvoi du lien) ; les refus de réservation affichent le message de
  l'API (`apiFailure`).
- AC 7 : date en Europe/Paris, format `d/m/Y \à H\hi`.
- Tests : `AdminEventEditTest` (slug), `RegistrationReactivateTest`, `ReserveRegistrationTest` (réinscription),
  `AdminRegistrationCancellationTest` (YAML conservé), `RegistrationGameSelectionTest` (`submittedAt`),
  `RegistrationEligibilityTest` (email), `RegistrationSubmitTest` (date de l'email), `registration-fixes.test.ts`.

