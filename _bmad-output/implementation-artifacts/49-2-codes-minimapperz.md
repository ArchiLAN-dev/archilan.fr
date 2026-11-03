# Story 49.2 - Les codes Minimapperz : import et attribution aux inscriptions

**Epic :** 49 - Minimapperz (`planning-artifacts/epics/epic-49-minimapperz.md`)
**Statut :** review
**Date :** 2026-11-02
**Branche :** `feature/epic-49-story-2-codes-minimapperz` (partie de la 49.1)

## Story

En tant qu'admin, je veux coller la liste de codes que Minimapperz m'a donnée, afin que chaque inscrit reçoive le
sien sans que j'aie à les distribuer.

## Critères d'acceptation

1. **Import.** `POST /api/v1/admin/events/{id}/minimapperz/codes` avec `codes` (texte, un code par ligne). Lignes
   vides et doublons ignorés, une ligne avec un espace ou de plus de 100 caractères est écartée et renvoyée dans
   `invalid`. Réponse : `added`, `duplicates` (déjà présents), `invalid`, `overview`. Rien de valide : 422
   `invalid_minimapperz_codes`. 5 000 lignes au plus.
2. **Vue admin.** `GET .../minimapperz/codes` : `imported`, `assigned`, `free`, `registrantsWithoutCode` et la liste
   (code, membre, inscription toujours active ou non). Le front alerte quand il manque des codes.
3. **Attribution au joueur.** `POST /api/v1/events/{id}/minimapperz/code` (connecté) : un inscrit actif reçoit un
   code libre, puis toujours le même. `data: null` si l'intégration est coupée, l'event terminé, le membre non
   inscrit ou désinscrit, ou s'il ne reste aucun code libre. Visiteur : 401.
4. **Unicité.** Deux membres n'ont jamais le même code, un membre n'a jamais deux codes pour un event, même en cas
   d'appels simultanés (`FOR UPDATE SKIP LOCKED` + index unique `(event_id, user_id)`).
5. **En masse.** `POST .../minimapperz/codes/assign` donne un code à chaque inscrit actif qui n'en a pas ; réponse
   `assigned`, `withoutCode` (faute de codes libres), `overview`.
6. **Annulation.** Une inscription annulée garde son code (affiché « inscription annulée »). L'admin peut reprendre un
   code : `DELETE .../minimapperz/codes/{code}/assignment`, le code redevient libre ; 404 s'il n'était à personne.
7. **Front.** Page de l'event : carte « Ton code Minimapperz » (code, copier, lien vers la LAN) pour l'inscrit.
   Onglet Minimapperz de la page pelles : compteurs, import, « Attribuer à tous les inscrits », tableau avec
   « Reprendre ».
8. Routes admin réservées aux admins (403).

## Tâches

- [x] Entité `MinimapperzCode` (clé `(event_id, code)`), repository DBAL atomique, migration `Version20261102120001`.
- [x] `MinimapperzCodeList` (lecture du texte collé), `MinimapperzSlug::lanUrl`.
- [x] `MinimapperzCodesQueryInterface` + `DbalMinimapperzCodesQuery`, service `MinimapperzCodes`, commande
  `ClaimMinimapperzCode`.
- [x] Routes admin dans `AdminMinimapperzController`, route joueur `MinimapperzCodeController`.
- [x] Tests : `MinimapperzCodesTest` (10 fonctionnels), `MinimapperzCodeListTest`.
- [x] Front : `admin-minimapperz-codes.tsx`, `minimapperz-code-card.tsx`, appels dans `minimapperz-api.ts`, tests jest.
- [x] Gates API et front verts.

## Notes de dev

- La route joueur est un `POST` : le premier appel attribue un code. Le front l'appelle via TanStack Query
  (`retry: false`), comme le bouton du blind test.
- Pas de réattribution automatique d'un code d'inscription annulée : il a pu être déjà saisi sur Minimapperz.
- Question ouverte de l'epic : un code Minimapperz est-il à usage unique ? À confirmer avant la 49.3.
