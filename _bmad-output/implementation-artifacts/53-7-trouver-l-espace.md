# Story 53.7 - Trouver l'espace

**Status:** in-progress (vérification réelle à faire)
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-7-trouver-l-espace` (empilée sur `feature/epic-53-story-3-outils-de-l-event`, PR #1086)
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

- [x] **Task 1** (AC 1, 2) - Bandeau, retrait des cartes de la page publique.
- [x] **Task 2** (AC 3) - Entrée de menu, champs d'`account/registrations`.
- [x] **Task 3** (AC 4) - Lien du mail de confirmation.
- [x] **Task 4** (AC 5) - Page QR admin, entrée du hub.
- [ ] **Task 5** (AC 6-9) - Tests, gates, vérification réelle. Tests et gates statiques faits ; tests fonctionnels API et vérification réelle à faire.

## Dev Notes

- Le message épinglé sur Discord se fait à la main (hors code) : le mentionner dans la PR avec l'URL à épingler.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- **Page publique** : `EventRegistrationCta` rend `RegisteredCta` pour un inscrit, avec un bandeau « Tu es inscrit. Ton espace... »,
  le bouton « Mon event », puis « Modifier mon inscription » (ou « Rejoindre ma partie » pendant l'événement, inscription
  confirmée). Le non-inscrit garde le CTA habituel. Blind test, Minimapperz et sondages sont retirés de la page publique.
- **Checklist** : la ligne sondages mène à `mon-event#outils` (la section outils porte l'ancre `outils`), le bandeau
  n'étant plus sur la page publique.
- **Menu** : `pickHubEntry` (pur) choisit l'événement en cours, sinon le plus proche qui commence dans les 14 jours ;
  inscriptions annulées, événements en brouillon ou terminés, passés ou sans date exclus ; sans date de fin, l'événement
  dure sa journée. `useHubEntry` lit `account/registrations` (même clé de cache que le compte). Entrée « Mon event » en
  tête du menu desktop (`AccountMenuLinks`, prop `hubEntry`) et mobile (`AuthNavMobile`).
- **API `account/registrations`** : ajout de `eventStartsAt`, `eventEndsAt` (ISO 8601) et `eventStatus`. `eventSlug`
  existait déjà (c'est l'id de l'événement). Champs facultatifs dans la garde front.
- **Mail de confirmation** : paragraphe et bouton « Mon espace pour l'événement » vers `/evenements/<id>/mon-event`
  (HTML et texte).
- **QR** : page `/admin/evenements/{id}/qr-espace` (`HubQrSheet`), QR dessiné en SVG par `qrPath`, titre, « Perdu ?
  Scanne-moi », URL à épingler sur Discord. Impression : règle `@media print` dans `globals.css` (A5, seule la feuille
  `data-print-sheet` est imprimée). PNG : dessiné sur un canvas au clic, sans dépendance. Entrée « QR code de l'espace »
  dans le groupe « Jour J » de la barre et de la vue d'ensemble du hub.
- **Récap et session** : lien « Mon espace pour l'événement » en haut (`HubLink`, composant serveur).
- Gates : front typecheck, lint, test (255 suites, 1621 tests), build verts ; API phpstan, cs-fixer, ddd, rector,
  tests unitaires (1824) verts. `AccountRegistrationsTest` (fonctionnel) non lancé en local (Postgres arrêté).
- Correctif embarqué de la pile : `RegistrantChecklistTest::checklist()` (53.2) ne passait pas PHPStan en CI, corrigé
  sur la branche 53.2 et remonté.

### File List

- `api/src/Communications/Application/Email/RegistrationConfirmationEmail.php` (modifié)
- `api/src/Communications/Application/Handler/RegistrationConfirmationHandler.php` (modifié)
- `api/src/Registrations/Application/Query/AccountRegistrationsQueryInterface.php` (modifié)
- `api/src/Registrations/Infrastructure/Dbal/DbalAccountRegistrationsQuery.php` (modifié)
- `api/tests/Unit/Communications/HtmlEmailsTest.php` (modifié)
- `api/tests/Functional/AccountRegistrationsTest.php` (nouveau)
- `frontend/src/app/(admin)/admin/evenements/[eventId]/qr-espace/page.tsx` (nouveau)
- `frontend/src/app/(public)/evenements/[eventSlug]/page.tsx` (modifié)
- `frontend/src/app/(public)/evenements/[eventSlug]/inscription/[registrationId]/recap/page.tsx` (modifié)
- `frontend/src/app/(public)/evenements/[eventSlug]/inscription/[registrationId]/session/page.tsx` (modifié)
- `frontend/src/app/globals.css` (modifié)
- `frontend/src/components/public-shell.tsx` (modifié)
- `frontend/src/features/admin/event-hub/admin-event-hub.tsx` (modifié)
- `frontend/src/features/admin/event-hub/admin-event-nav.tsx` (modifié)
- `frontend/src/features/admin/event-hub/event-hub.test.tsx` (modifié)
- `frontend/src/features/auth/auth-api.ts` (modifié)
- `frontend/src/features/auth/user-menu.tsx` (modifié)
- `frontend/src/features/auth/user-menu.test.tsx` (modifié)
- `frontend/src/features/events/event-registration-cta.tsx` (modifié)
- `frontend/src/features/registrant-hub/hub-entry.ts` (nouveau)
- `frontend/src/features/registrant-hub/hub-entry.test.ts` (nouveau)
- `frontend/src/features/registrant-hub/hub-link.tsx` (nouveau)
- `frontend/src/features/registrant-hub/hub-qr-sheet.tsx` (nouveau)
- `frontend/src/features/registrant-hub/hub-qr-sheet.test.tsx` (nouveau)
- `frontend/src/features/registrant-hub/registrant-checklist.tsx` (modifié)
- `frontend/src/features/registrant-hub/registrant-checklist.test.tsx` (modifié)
- `frontend/src/features/registrant-hub/registrant-hub.tsx` (modifié)

