# Story 4.21: Événement privé - adhérent ou code d'accès

**Status:** review
**Epic:** 4 - Inscription aux événements
**Date:** 2026-10-12

## Story

En tant qu'adhérent ArchiLAN à jour de sa cotisation,
je veux m'inscrire à un événement privé sans code,
afin que l'adhésion me donne accès aux événements réservés.

En tant que joueur non adhérent qui a reçu le code d'accès d'un événement privé,
je veux pouvoir m'inscrire une fois le code saisi,
afin que le code serve vraiment à quelque chose.

## Contexte

Un événement privé (`is_public = false`, story 3.4) peut porter un mot de passe. La story 4.2 a ajouté la saisie du
code sur la page d'inscription : l'API vérifie le code et journalise la tentative (`event_private_access_log`), puis le
navigateur affiche le bouton d'inscription.

Bug : `ReserveRegistration` refuse tout événement privé (`private_event`) sans regarder si le code a été donné. Personne
ne peut donc s'inscrire à un événement privé, code ou pas. Par ailleurs, le texte affiché annonce un événement « réservé
aux membres ArchiLAN », mais rien ne vérifie l'adhésion.

Règle voulue (décision de Jean, 2026-10-12) : un événement privé est ouvert à **un adhérent à jour ou à un membre qui a
donné le bon code**.

## Critères d'acceptation

1. **Adhérent** : un membre dont l'adhésion est active (`ActiveMembershipQueryInterface::hasActiveMembership`) est
   éligible à un événement privé et peut s'y inscrire, sans code, avec les mêmes autres règles (fenêtre d'inscription,
   capacité, email vérifié).
2. **Code** : un membre non adhérent qui a déjà donné le bon code pour cet événement (une ligne `granted = true` dans
   `event_private_access_log` pour lui et cet événement) est éligible et peut s'y inscrire. L'accès reste acquis tant que
   le journal le garde (365 jours par défaut).
3. **Ni l'un ni l'autre** : l'éligibilité renvoie `private_event` et l'inscription est refusée (422 `not_eligible`), comme
   aujourd'hui.
4. **Une seule règle** : l'éligibilité (`GET .../registration-eligibility`) et la réservation (`POST .../registrations`)
   s'appuient sur le même service, pour qu'elles ne divergent pas.
5. **Textes** : la page d'inscription et la page de l'événement disent « réservé aux adhérents » (et non « aux membres »),
   avec un lien vers `/adhesion` et la saisie du code pour les autres. Le formulaire admin décrit l'accès privé comme
   « adhérents ou code d'accès ».
6. **Bouton d'inscription** : un événement privé garde son statut (ouvert, à venir, terminé) sur sa carte et sur sa
   page, avec la mention « réservé aux adhérents, ou sur code d'accès ». Le bouton « S'inscrire » s'affiche donc comme
   pour un événement public (avant, le statut « Réservé aux membres » le masquait, et un événement privé terminé restait
   dans les événements à venir).
7. Tests (adhérent accepté, code accepté, mauvais code refusé, adhésion expirée refusée, éligibilité alignée) et gates
   verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-4) - Service `PrivateEventAccess` (Registrations, Application) : public, adhérent actif ou code
  accordé ; branché dans `RegistrationEligibility::check` et `ReserveRegistration::reserve`.
- [x] **Task 2** (AC 7) - Tests fonctionnels réservation et éligibilité.
- [x] **Task 3** (AC 5, 6) - Textes front (page d'inscription, page événement, formulaire admin) ; tests.
- [x] **Task 4** - Gates.

## Hors périmètre

- Masquer un événement privé du catalogue public.
- Ouvrir un événement privé aux seuls adhérents, sans code possible.

## Dev Agent Record

- API : `Registrations/Application/Query/PrivateEventAccess::allows(Event, userId)` (public, adhésion active via
  `ActiveMembershipQueryInterface`, ou code accordé via `PrivateAccessGrantedQueryInterface::countGrantedForUser`).
  `RegistrationEligibility::check` prend maintenant l'id du membre ; `ReserveRegistration` remplace son refus
  systématique par ce service.
- Front : `PublicEvent.membersOnly` remplace le statut `members-only` ; mention sur la carte, mention et lien `/adhesion` sur la page
  de l'événement et la page d'inscription ; libellé du formulaire admin.
- Tests : `tests/Functional/PrivateEventRegistrationTest.php` (adhérent, code, mauvais code, adhésion expirée, code d'un
  autre événement), `public-events-api.test.ts`.
