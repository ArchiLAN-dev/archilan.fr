# Story 49.1 - Brancher un event sur Minimapperz (socle)

**Epic :** 49 - Minimapperz (`planning-artifacts/epics/epic-49-minimapperz.md`)
**Statut :** review
**Date :** 2026-11-02
**Branche :** `feature/epic-49-story-1-socle-minimapperz`

## Story

En tant qu'admin, je veux renseigner le slug et la clé Minimapperz d'un événement et régler le barème, afin que le
site puisse lire les parties de cet event.

## Critères d'acceptation

1. **Réglage par event.** Une ligne `minimapperz_setting` par event (pas de ligne = intégration coupée) : activé,
   slug, clé API, pelles par partie jouée, pelles en plus pour une victoire, plafond par joueur.
2. **Bornes.** Pelles par partie de 0 à 100, bonus victoire de 0 à 100, plafond de 1 à 10 000. Activer exige un
   slug, une clé enregistrée et au moins 1 pelle par partie ou par victoire. Sinon 422
   `invalid_minimapperz_settings` avec une erreur par champ.
3. **Slug.** L'admin peut coller le slug (`archilan-test`) ou l'adresse de la LAN
   (`https://app.minimapperz.com/lan/archilan-test/`) : le site garde le slug seul. Slug accepté :
   lettres, chiffres, tirets et underscores, 100 caractères au plus.
4. **La clé ne ressort jamais.** `GET` et `PUT` renvoient `apiKeySet` et `apiKeyEnd` (3 derniers caractères, ou
   null), jamais la clé. Au `PUT`, `apiKey` absent ou vide garde la clé existante ; une valeur la remplace (sans
   espace, 200 caractères au plus) ; `clearApiKey: true` l'efface. La clé n'est jamais journalisée. Stockage en base,
   en clair (décision de l'epic).
5. **Test de connexion.** `POST /api/v1/admin/events/{eventId}/minimapperz/check` appelle
   `GET /api/v1/organiser/events/{slug}` chez Minimapperz avec la clé enregistrée et renvoie `status` :
   `ok` (avec le nom de l'event Minimapperz, ses dates d'ouverture et de fermeture, ouvert ou non),
   `not_configured` (slug ou clé manquant, aucun appel), `unauthorized` (401/403 : clé ou slug invalide),
   `unreachable` (erreur réseau, délai dépassé, autre statut, réponse illisible).
6. **Admin seulement.** Les trois routes refusent un non-admin (403) ; un event inconnu donne 404.
7. **Front.** Un onglet « Minimapperz » dans la page pelles de l'event (`/admin/evenements/{id}/pelles`), à côté
   du blind test, disparaît une fois l'event terminé comme les autres onglets d'action. Formulaire : interrupteur,
   slug ou adresse, clé (champ mot de passe, indication « clé enregistrée, se termine par ...XXX »), bouton effacer
   la clé, barème, bouton « Tester la connexion » avec le résultat lisible.
8. **Aucun appel réel** à Minimapperz dans les tests (MockHttpClient).

## Tâches

- [x] Entité `Wallet\Domain\Entity\MinimapperzSetting`, repository (interface Domain + Doctrine), migration.
- [x] Port `Wallet\Application\Port\MinimapperzClientInterface::event(slug, apiKey)`, record
  `Application\Support\MinimapperzEvent`, exceptions `MinimapperzUnauthorizedException` /
  `MinimapperzUnavailableException` (Application/Exception).
- [x] Client `Wallet\Infrastructure\Http\MinimapperzHttpClient` (base URL `MINIMAPPERZ_API_URL`, timeout court).
- [x] Service `Wallet\Application\Service\MinimapperzSettings` (`of`, `change`, `checkConnection`).
- [x] Contrôleur `AdminMinimapperzController` (GET, PUT, POST check).
- [x] Tests : unitaires (normalisation du slug, client HTTP), fonctionnels (réglage, bornes, clé jamais renvoyée,
  test de connexion dans les 4 cas, accès).
- [x] Front : `minimapperz-api.ts`, `admin-minimapperz-card.tsx`, onglet dans `admin-event-pelles-page.tsx`, tests jest.
- [x] Gates API et front verts.

## Notes de dev

- Modèle : `BlindTestSetting` / `BlindTestSettings` / `AdminBlindTestController` (story 41.40).
- Le port n'expose que `event()` : `games`, `players` et `ranking` arrivent avec 49.3 et 49.4.
- API Minimapperz : voir la section Constat de l'epic. Base URL par défaut :
  `https://backendv1-74040419982.us-central1.run.app`.
- Clé de test de Jean sur l'event `archilan-test` : ne jamais la mettre dans le dépôt.
