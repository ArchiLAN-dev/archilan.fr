# Story 4.15 - Tous les mails du site en HTML

**Epic :** 4 - Inscriptions
**Statut :** review
**Date :** 2026-11-03
**Branche :** `feature/epic-4-story-15-mails-html`

## Contexte

Retour : le mail de confirmation d'inscription arrive en texte brut. Seuls 6 mails (confirmation d'adresse, mot de
passe, 4 mails d'adhésion) utilisaient le gabarit HTML `ArchilanEmail::htmlLayout` ; 8 n'avaient qu'un corps texte.

## Critères d'acceptation

1. Les 8 mails ont une version HTML dans le gabarit ArchiLAN (logo, carte, pied de page), la version texte restant
   envoyée à côté :
   confirmation d'inscription, relance de confirmation, place libérée, event complet (admin), message de l'admin à un
   inscrit, partie lancée, pause sans sauvegarde (admin), redémarrage échoué (admin).
2. **Confirmation d'inscription** : titre, encadré date et lieu, liste des jeux, bouton « Voir mon inscription » vers
   le récap (`/evenements/{event}/inscription/{inscription}/recap`), aussi cité dans la version texte.
3. **Boutons** : relance -> « Confirmer mon inscription », place libérée -> « Voir l'événement », event complet ->
   « Voir les inscriptions » (backoffice), message de l'admin -> « Voir mon inscription ».
4. Tout texte venant d'un membre ou d'un admin est échappé ; le message de l'admin garde ses retours à la ligne.
5. Un message de confirmation mis en file avant le déploiement (sans les ids) part toujours, sans bouton.

## Tâches

- [x] Briques HTML dans `ArchilanEmail` : `heading`, `greeting`, `paragraph`, `textBlock`, `strong`, `infoBox`,
  `bulletList`, `button`, `note`, `signature`, `logoUrlFor`, `escape`.
- [x] `htmlBody()` des 8 mails ; `siteUrl` (et le lien utile) passé par les handlers et `SendMessageToRegistrant`.
- [x] `RegistrationConfirmationMessage` : `eventId`, `registrationId` (vides par défaut), renseignés par
  `RegistrationSubmission`.
- [x] Tests : `tests/Unit/Communications/HtmlEmailsTest.php`.

## Dev Notes

- Aucune migration, aucune variable d'env (`FRONTEND_ORIGIN` déjà lié en `$siteUrl`).
- Les 6 mails déjà stylés ne changent pas ; ils pourront passer sur les briques plus tard.
