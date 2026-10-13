# Story 4.23: Statuts de l'événement, accès privé et réservations non confirmées

**Status:** review
**Epic:** 4 - Inscription aux événements
**Date:** 2026-10-12

## Story

En tant que membre qui suit l'ArchiLAN 4,
je veux que la page de l'événement dise où en sont les inscriptions et me mène au bon endroit à chaque étape,
afin de savoir quand m'inscrire, si c'est encore possible, et de retrouver ma partie le jour J.

En tant qu'organisateur,
je veux que les places réservées mais jamais confirmées soient rappelées puis libérées,
afin que la jauge corresponde aux joueurs qui viendront vraiment.

## Contexte

Suite de la revue des événements (2026-10-12) et des stories 4.21 / 4.22. Décision de Jean : pas de billet HelloAsso
pour l'ArchiLAN 4. On rejoint un événement privé en étant adhérent à jour ou avec son mot de passe ; l'inscription sur
le site est la seule inscription. Le lien HelloAsso de l'événement reste vide, donc aucun bloc de billetterie ne
s'affiche : rien à changer côté paiement.

Constats de la revue repris ici :

- `toCardStatus` (`frontend/src/features/events/public-events-api.ts`) ne produit que `completed`, `open` ou `upcoming` :
  `full` n'est jamais calculé, la fermeture des inscriptions est ignorée, et un événement en cours s'affiche « ouvert »
  avec le bouton « S'inscrire ».
- `registrationOpensAt` est dans la réponse de l'API mais n'est pas repris par `toPublicEvent` ; la page dit « les
  inscriptions ouvriront plus tard ».
- Pendant l'événement, un inscrit n'a que « Modifier mon inscription » ; la page de session
  (`/evenements/{slug}/inscription/{id}/session`) n'est liée que depuis le compte.
- Le formulaire « J'ai un code d'accès » s'affiche même quand l'événement n'a pas de mot de passe, et répond « code
  invalide ».
- `SessionRecapAudience::canView` renvoie `isPublic()` pour une session d'événement et `EventRecapIndexQuery` renvoie
  `null` pour un événement privé : les participants d'un événement privé ne voient ni leurs récaps ni la liste des
  parties.
- Une réservation jamais confirmée (`submittedAt` nul) compte dans la jauge (`countConfirmed`) sans donner accès à la
  session (`submitted_at` requis), et n'expire jamais.

## Critères d'acceptation

1. **Statut de l'événement**, calculé à partir des dates, de la capacité et du statut de l'API, identique sur la carte
   et sur la page :
   - `upcoming` « Inscriptions bientôt ouvertes » avant `registrationOpensAt` ;
   - `open` « Inscriptions ouvertes » pendant la fenêtre, s'il reste des places ;
   - `full` « Complet » pendant la fenêtre, sans place restante ;
   - `closed` « Inscriptions fermées » après `registrationClosesAt`, avant le début ;
   - `in-progress` « En cours » quand l'API dit `in-progress` ;
   - `completed` « Terminé ».
   Le bouton « S'inscrire » ne s'affiche que pour `open`. Les libellés et CTA morts (« Liste d'attente ») disparaissent.
2. **Date d'ouverture** : avant l'ouverture, la carte et la page affichent la date et l'heure d'ouverture des
   inscriptions (heure de Paris), et la page affiche aussi la date de fermeture. Les dates de l'événement affichent
   l'heure de début et, si l'événement dure plusieurs jours, la date de fin.
3. **Le jour J** : pendant l'événement (`in-progress`), un membre inscrit et confirmé voit « Rejoindre ma partie » vers
   sa page de session, depuis la page de l'événement. Un inscrit non confirmé ou un visiteur ne voit pas de bouton
   d'inscription.
4. **Code d'accès** : le formulaire « J'ai un code d'accès » ne s'affiche que si l'événement a un mot de passe
   (`hasPrivateAccessPassword` ajouté à la réponse d'éligibilité). Sans mot de passe, le texte dit que l'événement est
   réservé aux adhérents et propose le lien `/adhesion`. Une réponse en échec qui n'est pas « code refusé » (trop de
   tentatives, inscriptions fermées) affiche un message distinct de « code invalide ».
5. **Récaps d'un événement privé** : les récaps, le fil et la liste des parties d'un événement privé sont visibles par
   ses participants (inscription confirmée) et par les admins ; ils restent cachés aux autres. Un événement public ne
   change pas.
6. **Rappel des réservations non confirmées** : 48 h avant `registrationClosesAt`, chaque membre dont la réservation
   n'est pas confirmée reçoit un email « Confirme ton inscription » avec le lien vers son récapitulatif. Un seul rappel
   par réservation (horodaté sur l'inscription, migration réversible), même si la tâche tourne plusieurs fois. Une
   réservation faite après ce moment ne reçoit pas de rappel.
7. **Libération à la fermeture** : à la fermeture des inscriptions, les réservations encore non confirmées sont
   annulées. La jauge temps réel est republiée, chaque membre concerné reçoit un email « Ta place a été libérée », et
   l'admin les voit comme annulées dans le tableau des inscriptions. Une réservation confirmée n'est jamais touchée.
8. **Tâche planifiée** : rappel et libération passent par une tâche du scheduler (une passe par heure suffit),
   idempotente, qui ne traite que les événements publiés dont les inscriptions sont concernées.
9. Tests (calcul des statuts aux bornes, date d'ouverture affichée, bouton du jour J, formulaire de code selon le mot
   de passe, droits de lecture des récaps privés, rappel unique, libération sans toucher aux confirmées, jauge
   republiée) et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Front : `EventStatus` étendu, `toCardStatus` / `toPublicEvent` (dates d'ouverture et de
  fermeture), libellés de la carte et de la page, CTA ; formatage des dates en Europe/Paris ; tests.
- [x] **Task 2** (AC 3) - Front : CTA « Rejoindre ma partie » (`event-registration-cta.tsx`, à partir de
  `my-registration`, qui doit exposer la confirmation) ; tests.
- [x] **Task 3** (AC 4) - API : `hasPrivateAccessPassword` dans `RegistrationEligibility` ; front : formulaire
  conditionnel, messages d'échec ; tests.
- [x] **Task 4** (AC 5) - API : règle « participant confirmé ou admin » pour une session d'événement privé dans
  `SessionRecapAudience`, et dans `EventRecapIndexQuery` ; tests fonctionnels.
- [x] **Task 5** (AC 6, 7, 8) - API : colonne `reminder_sent_at` (migration), message planifié (rappel puis libération),
  emails (Communications), republication de la jauge ; tests.
- [x] **Task 6** (AC 9) - Gates ; vérification visuelle de la carte et de la page (états avant ouverture, ouvert,
  complet, fermé, en cours).

## Notes techniques

- La page de l'événement est en ISR (5 min) : un statut calculé au rendu serveur peut avoir 5 minutes de retard. Le
  bouton d'inscription reste protégé par l'éligibilité ; pas besoin d'un calcul client pour les statuts.
- La libération réutilise `Registration::cancel` ; une réinscription reste impossible ensuite puisque les inscriptions
  sont fermées.

## Hors périmètre

- Billetterie HelloAsso des événements (non utilisée pour l'ArchiLAN 4).
- Améliorations admin (galerie, sélection de jeux, export, dépublication) et textes mineurs de la revue.

## Dev Agent Record

- AC 1, 2 : `features/events/event-schedule.ts` (`eventStatusAt`, dates en Europe/Paris) ; `EventStatus` étendu
  (`closed`, `in-progress`) ; `PublicEvent` porte la fenêtre d'inscription ; carte et page (badge, disponibilité, ligne
  « Inscriptions », date avec heures, CTA de carte « S'inscrire » / « Voir l'événement » / « Suivre l'événement »).
- AC 3 : `my-registration` renvoie `confirmed` ; `EventRegistrationCta` en mode `inProgress` : « Rejoindre ma partie »
  vers `/inscription/{id}/session` pour un inscrit confirmé, rien sinon.
- AC 4 : `hasPrivateAccessPassword` dans l'éligibilité ; formulaire de code seulement s'il existe ; « code invalide »
  distinct d'un échec de vérification. Le déblocage local après un bon code reste (la requête d'éligibilité ne change
  jamais sa décision au rafraîchissement).
- AC 5 : `SessionRecapAudience::canViewEvent` (public, inscrit confirmé via `ActiveRegistrationQueryInterface`, admin),
  utilisé par les récaps, le fil et `EventRecapIndexQuery` (le contrôleur passe l'utilisateur facultatif). Front :
  `EventPartiesSection` déplacé dans `features/events/event-parties.tsx`, `PrivateEventParties` charge la liste côté
  navigateur pour un événement privé (le rendu serveur est anonyme).
- AC 6, 7, 8 : colonne `registration.reminder_sent_at` (`Version20261012110000`) ; `Registration::isAwaitingConfirmation`,
  `needsConfirmationReminder`, `markReminded` (remis à zéro par `reactivate`) ; `ProcessUnconfirmedRegistrationsMessage`
  planifié à `20 * * * *` (async) et son handler : rappel à partir de 48 h avant la fermeture, libération après, jauge
  republiée ; emails `RegistrationConfirmationReminderEmail` et `RegistrationReleasedEmail` (Communications).
- Tests : `ProcessUnconfirmedRegistrationsTest`, `EventRecapIndexEndpointTest`, `SessionRecapEndpointTest`,
  `PrivateEventRegistrationTest` et `ReserveRegistrationTest` (champs ajoutés), `event-schedule.test.ts`,
  `registration-fixes.test.ts`, `public-events-api.test.ts`.
- Vérification visuelle (événements de démo en base de dev) : carte et page dans les états à venir, ouvert, complet,
  fermé, en cours ; pas de bouton pour un visiteur pendant l'événement.

