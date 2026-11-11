# Story 53.3 - Les outils de l'event

**Status:** in-progress (vérification réelle à faire)
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-3-outils-de-l-event` (empilée sur `feature/epic-53-story-2-checklist-inscrit`, PR #1085)
**Dépend de:** 53.1.

## Story

En tant qu'inscrit,
je veux retrouver dans mon espace tous les outils de l'événement (ma partie, blind test, codes, pelles, Passeur),
afin de ne pas devoir savoir où chacun se cache.

## Contexte (cartographie du 2026-11-11)

Composants existants, chacun lit son endpoint et se masque quand rien n'est actif :

| Outil | Composant | Endpoint |
|---|---|---|
| Blind test | `features/wallet/blind-test-join-button.tsx` | `GET /events/{id}/blind-test/access` |
| Minimapperz | `features/wallet/minimapperz-code-card.tsx` | `/events/{id}/minimapperz/code` |
| Sondages | `features/surveys/event-survey-banner.tsx` | `GET /events/{id}/my-surveys` |
| Présence | `features/presence/presence-editor.tsx` (`PresenceCard`) | `GET/PUT /registrations/{id}/presence` |
| Diffusion | `features/streaming/broadcast-consent-card.tsx` | `GET/PUT /registrations/{id}/broadcast-consent` |
| Partie AP | `features/events/session-connection-gate.tsx` | `GET /registrations/{id}/session-connection` |
| Passeur | `features/passeur/passeur-notice.tsx` (`PasseurNoticeCard`) | `/sessions/{sid}/passeur/notice` |
| Pelles | `/compte/pelles` | `/me/wallet` |

Blind test, Minimapperz et sondages sont aujourd'hui sur la page publique
(`app/(public)/evenements/[eventSlug]/page.tsx` l. 216-221) ; présence et diffusion sur le récapitulatif et la page
session de l'inscription.

## Décisions

1. **Section « Les outils de l'event »** dans l'espace, une carte par outil, dans cet ordre :
   1. **Ma partie Archipelago** : résumé de `session-connection` (pas de session / en préparation / en cours avec
      l'adresse de connexion copiable / terminée), bouton « Détail de ma partie » vers
      `inscription/[id]/session`. Composant léger `HubSessionCard` : on ne recopie pas `SessionConnectionGate`
      (flux, patches) dans l'espace.
   2. **Blind test** : `BlindTestJoinButton` tel quel.
   3. **Minimapperz** : `MinimapperzCodeCard` tel quel.
   4. **Sondages** : `EventSurveyBanner` tel quel.
   5. **Pelles** : `HubPellesCard`, solde lu sur `/me/wallet` (première page) et lien « Mes pelles ».
   6. **Le Passeur** : `PasseurNoticeCard` quand l'inscrit a une session en cours (même condition que sur la page
      session).
   7. **Présence** et **diffusion** : `PresenceCard` et `BroadcastConsentCard` tels quels (ancre `#presence` pour la
      checklist de 53.2).
2. **Lien d'aide** sur chaque carte (« C'est quoi ? ») vers le guide correspondant, `/aide/<slug>` (53.5). Tant que
   53.5 n'est pas livrée, le lien est absent (constante de slugs partagée, carte sans lien si le guide n'existe
   pas : lecture de la liste des guides publiés, ou absence du lien jusqu'à 53.5, au choix du dev).
3. **Page publique** : les cartes blind test, Minimapperz et sondages en sont retirées (elles ne concernent que
   les inscrits). À leur place, le bandeau « Ton espace » de 53.7. **Tant que 53.7 n'est pas livrée, ne rien retirer** : cette
   story ajoute, 53.7 retire.
4. Les cartes qui se masquent d'elles-mêmes (blind test fermé, pas de code) ne laissent pas de trou dans la grille.

## Critères d'acceptation

1. L'espace montre les cartes des outils actifs pour l'événement et l'inscrit, et seulement celles-là.
2. La carte partie affiche l'adresse de connexion quand la session tourne, copiable en un geste.
3. Le solde de pelles est affiché avec un lien vers l'historique.
4. Présence et diffusion se modifient depuis l'espace comme depuis le récapitulatif.
5. Le Passeur apparaît pendant une session en cours.

## Tests et gates

6. Front : `HubSessionCard` (quatre états), `HubPellesCard`, composition de la section (cartes masquées sans trou).
7. `pnpm gates` vert. Pas de changement API attendu (si un résumé de session manque, le dire et l'ajouter à
   `session-connection` plutôt qu'un nouvel endpoint).
8. Vérification réelle sur un événement de démo avec session lancée, blind test ouvert et code Minimapperz.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - `HubSessionCard`.
- [x] **Task 2** (AC 3) - `HubPellesCard`.
- [x] **Task 3** (AC 1, 4, 5) - Section, composition des cartes existantes, ancres.
- [ ] **Task 4** (AC 6-8) - Tests, gates, vérification réelle. Tests et gates faits ; vérification réelle à faire.

## Dev Notes

- Le blind test et Minimapperz ont été pensés pour la page publique : vérifier leur rendu dans une carte étroite.
- Carte « Diffuser pour la régie » (epic 47, diffusion locale) : hors périmètre, prévoir sa place après la diffusion.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- `RegistrantHubTools` (`features/registrant-hub/hub-tools.tsx`) compose, dans l'ordre : carte partie, Passeur (session
  en cours), blind test, Minimapperz, sondages, pelles, présence (ancre `#presence`), diffusion. Les cartes
  existantes lisent leur endpoint et ne rendent rien quand l'outil est éteint : pas de cadre vide.
- `HubSessionCard` (inscription confirmée seulement) : pas de partie / en préparation (mêmes statuts que la page
  session) / en cours avec `ConnectionFields` (copie de l'adresse, du port, du nom de slot) / en pause / terminée /
  interrompue, lien « Détail de ma partie ». Relue chaque minute.
- `HubPellesCard` : or et pelles de l'événement depuis `/me/wallet`, lien vers `/compte/pelles`.
- `PresenceCard` et `BroadcastConsentCard` gardent leur signature (paramètres de route) et délèguent à
  `RegistrationPresenceCard` / `RegistrationBroadcastConsentCard` (id d'inscription connu), utilisées par l'espace.
- La vue de l'espace reçoit les outils en `tools` (ReactNode) depuis la garde : elle reste un rendu pur, testable sans
  client de requêtes.
- Checklist : la ligne présence mène à `mon-event#presence`.
- Décision 2 (lien « C'est quoi ? ») : pas de lien tant que la 53.5 n'existe pas.
- Décision 3 : rien retiré de la page publique (la 53.7 s'en charge).
- Pas de changement API.
- Gates front : typecheck, lint, test (253 suites, 1610 tests), build verts.

### File List

- `frontend/src/features/registrant-hub/hub-tools.tsx` (nouveau)
- `frontend/src/features/registrant-hub/hub-tools.test.tsx` (nouveau)
- `frontend/src/features/registrant-hub/registrant-hub.tsx` (modifié)
- `frontend/src/features/registrant-hub/registrant-checklist.tsx` (modifié)
- `frontend/src/features/presence/presence-editor.tsx` (modifié)
- `frontend/src/features/streaming/broadcast-consent-card.tsx` (modifié)
