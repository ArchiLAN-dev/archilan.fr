# Story 53.7 - Trouver l'espace

**Status:** ready-for-dev
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-7-trouver-l-espace` (depuis `develop`)
**Dépend de:** 53.1, 53.3.

## Story

En tant qu'inscrit perdu,
je veux tomber sur mon espace où que je sois (page de l'événement, menu, mail, table de la LAN),
afin de ne jamais avoir à demander où trouver les infos.

## Contexte (cartographie du 2026-11-11)

- Page publique : `app/(public)/evenements/[eventSlug]/page.tsx` ; CTA d'inscription `features/events/event-registration-cta.tsx`
  (`fetchMyRegistrationCta`).
- Menu : `components/public-shell.tsx`, `UserMenu` (`features/auth/user-menu.tsx`, liens l. 149-152), mobile
  `AuthNavMobile` (l. 132).
- Inscriptions du membre : `GET /api/v1/account/registrations` (`AccountRegistrationsController.php:24`).
- Mail de confirmation : `api/src/Communications/Application/Email/RegistrationConfirmationEmail.php` (classe PHP,
  pas de Twig), envoyé par `RegistrationConfirmationHandler`.
- Hub admin d'un événement : `features/admin/event-hub/admin-event-nav.tsx:15-40`, groupe « Jour J ».
- QR : dépendance `qrcode` 1.5.4 déjà présente dans le front.

## Décisions

1. **Bandeau sur la page publique** pour un inscrit : « Tu es inscrit · Ton espace : ce qu'il te reste à faire, tes
   codes, les infos pratiques » + bouton « Mon event ». Rendu par `EventRegistrationCta` dans le cas `registered`.
   **Retirer** de la page publique `BlindTestJoinButton`, `MinimapperzCodeCard` et `EventSurveyBanner`
   (l. 216-221), désormais dans l'espace (53.3).
2. **Menu du compte** : entrée « Mon event » (desktop et mobile) quand le membre a une inscription active à un
   événement en cours ou qui commence dans les 14 jours ; plusieurs : l'entrée mène au plus proche, les autres
   restent accessibles depuis « Mon espace ». Lecture sur `account/registrations` (ajouter `eventSlug`, `startsAt`,
   `endsAt`, `eventStatus` à la réponse si absents).
3. **Mail de confirmation** : lien « Ton espace pour l'événement » vers `/evenements/<slug>/mon-event`.
4. **QR code** : page admin `/admin/evenements/{id}/qr-espace`, entrée « QR code de l'espace » dans le groupe
   « Jour J » du hub. Affiche un QR (`qrcode`) de l'URL de l'espace, le titre de l'événement et la phrase « Perdu ?
   Scanne-moi ». Mise en page A5 imprimable (`@media print`, sans habillage admin), bouton « Imprimer » et
   « Télécharger en PNG ».
5. **Récapitulatif d'inscription** : lien « Mon espace » en haut de `inscription/[id]/recap` et `session`.

## Critères d'acceptation

1. Un inscrit voit le bandeau sur la page de l'événement ; un non-inscrit voit le CTA d'inscription habituel.
2. Blind test, Minimapperz et sondages ne sont plus sur la page publique et restent accessibles dans l'espace.
3. L'entrée « Mon event » apparaît dans le menu dans la fenêtre prévue, et seulement là.
4. Le mail de confirmation contient le lien.
5. L'admin imprime un QR qui ouvre l'espace sur un téléphone.

## Tests et gates

6. Front : bandeau (inscrit / non inscrit), entrée de menu (fenêtre, plusieurs inscriptions), page QR.
7. API : champs ajoutés à `account/registrations` (fonctionnel), contenu du mail (unitaire).
8. `composer gates` et `pnpm gates` verts.
9. Vérification réelle : impression du QR (aperçu), scan depuis un téléphone sur l'environnement local.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1, 2) - Bandeau, retrait des cartes de la page publique.
- [ ] **Task 2** (AC 3) - Entrée de menu, champs d'`account/registrations`.
- [ ] **Task 3** (AC 4) - Lien du mail de confirmation.
- [ ] **Task 4** (AC 5) - Page QR admin, entrée du hub.
- [ ] **Task 5** (AC 6-9) - Tests, gates, vérification réelle.

## Dev Notes

- Le message épinglé sur Discord se fait à la main (hors code) : le mentionner dans la PR avec l'URL à épingler.

## Dev Agent Record
