# Story 37.5: Surfaçage UI et mails

**Status:** blocked - dépend du résultat de 37.6
**Epic:** 37 - Accès WSS aux serveurs Archipelago
**Date:** 2026-08-10
**Dépend de :** 37.6 (quelles chaînes afficher) et 37.4 (le contrat qui les fournit).

## Story

En tant que joueur,
je veux voir sur la page de ma run l'adresse exacte à coller dans mon client, desktop ou web,
afin de me connecter sans avoir à deviner s'il faut préfixer `wss://`, ajouter le port, ou séparer
les deux.

## Context

Quatre panneaux affichent aujourd'hui l'adresse de connexion, tous bâtis sur le même `SecretField`
(masquage par champ, copie possible sans révéler - stories 17.21/17.22) :

| Surface | Fichier | Champs |
|---|---|---|
| Run personnelle | `features/personal-runs/connection-details.tsx:29-38` | Hôte, Port, Mot de passe, Mot de passe admin |
| Session d'événement | `features/events/session-connection-gate.tsx:308-310` | Adresse, Port, Mot de passe |
| Run hebdo (carte) | `features/weekly-runs/weekly-run-card.tsx:75` (`ConnectionPanel` local) | Hôte, Port, Mot de passe |
| Run hebdo (page slot) | `features/weekly-runs/weekly-run-slot-page.tsx:677-680` | Hôte, Port, Mot de passe |

Et un mail : `SessionRunningEmail`, qui écrit `Hôte : …` / `Port : …` en texte brut avec un mode
d'emploi en trois étapes.

Aucune de ces surfaces ne donne d'adresse chiffrée. Cette story les met à jour une fois que 37.4
fournit la donnée et que 37.6 a dit **quoi** afficher.

## Acceptance Criteria

### Ce qui est affiché

1. Chacune des quatre surfaces expose la ou les formes d'adresse conclues par 37.6, **verbatim**.
   Aucune reconstruction côté client : pas de concaténation `wss://` + hôte dans un composant.
2. Le couple hôte / port séparé **reste affiché**. Une famille de clients web l'exige, et l'admin
   comme le diagnostic s'en servent. On ajoute une ligne, on ne remplace pas les existantes.
3. Chaque forme affichée porte un libellé qui dit **à quoi elle sert**, pas seulement ce qu'elle
   est : un joueur doit pouvoir choisir sans lire la doc. La formulation retenue est cohérente sur
   les quatre surfaces.
4. La copie fonctionne sur chaque nouvelle valeur, masquée comme révélée - le comportement
   « streamer-safe » de `SecretField` est conservé tel quel.

### Aide en cas d'erreur

5. Le mode d'échec le plus probable observé en 37.6 fait l'objet d'une aide courte à l'endroit où le
   joueur se trompe. Pas une page d'aide : une phrase là où l'erreur se produit.

### Mention des clients tiers

6. Si l'UI oriente vers un client web tiers, elle dit **ce que ce tiers reçoit** : adresse, nom de
   slot et mot de passe transitent par un site que l'association ne contrôle pas. Une phrase suffit,
   mais elle est écrite et validée, pas sous-entendue.
7. Aucun lien de connexion pré-rempli **contenant le mot de passe** n'est produit sans décision
   explicite. Certains clients acceptent des paramètres d'URL (voir la fiche Topher's dans
   `docs/archipelago-web-clients.md`) : c'est pratique et c'est un mot de passe dans une URL vers un
   tiers. Trancher, et écrire ce qui a été tranché.

### Mail

8. `SessionRunningEmail` inclut l'adresse chiffrée, et son mode d'emploi reste juste : les trois
   étapes actuelles décrivent un client desktop, elles ne doivent pas devenir fausses pour autant.
9. Le mail reste lisible en texte brut, sans dépendre d'un rendu HTML pour être compréhensible.

### Qualité

10. Les tests existants sont mis à jour et couvrent les nouveaux champs :
    `connection-details.test.tsx` (masquage par champ), `secret-field.test.tsx`.
11. `pnpm gates` passe.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-4). Panneau des runs personnelles.
- [ ] **Task 2** (AC 1-4). Panneau des sessions d'événement.
- [ ] **Task 3** (AC 1-4). Les deux surfaces des runs hebdo.
- [ ] **Task 4** (AC 5-7). Aide à l'erreur et mention des tiers, formulations validées.
- [ ] **Task 5** (AC 8-9). Mail de session en cours.
- [ ] **Task 6** (AC 10-11). Tests et `pnpm gates`.

## Dev Notes

- **Quatre panneaux, deux composants, zéro partage.** `ConnectionDetails` sert les runs
  personnelles, `session-connection-gate` a ses propres `SecretField`, et `weekly-run-card` a un
  `ConnectionPanel` **local** dupliqué de la page slot. Modifier un seul des quatre est l'erreur
  probable de cette story. Deux options : les mettre à jour un par un, ou extraire un composant
  commun. L'extraction est tentante ; elle n'est **pas** demandée ici et élargirait le diff bien
  au-delà du sujet. Si elle est faite, qu'elle soit le premier commit, isolé et sans changement de
  comportement.
- **Ne pas construire l'adresse dans le front.** La forme exacte est une donnée mesurée, elle vient
  de l'API (37.4). Un `` `wss://${host}:${port}` `` dans un composant, c'est la garantie que la
  prochaine mise à jour d'un client tiers devra être corrigée à quatre endroits au lieu d'un.
- **Le libellé compte autant que la valeur.** Le résultat de 37.6, c'est justement qu'il n'existe
  pas une chaîne unique qui marche partout : l'UI doit rendre le choix évident sans transformer le
  panneau en notice.
- **Les tutoriels de l'epic 31 ne sont pas dans le dépôt** : ce sont des enregistrements en base,
  éditables par un admin. Les mettre à jour n'est pas du code et n'entre pas dans cette story ;
  `docs/archipelago-web-clients.md` reste la source durable dont ils sont la restitution joueur.
  Le signaler à l'admin fait partie de la clôture, pas des ACs.
- **Contraintes frontend** (`frontend/AGENTS.md`) : composants purs, pas de `process.env` direct
  (passer par `src/lib/env.ts`), types de props explicites, pas de `any`, pas de valeur d'API non
  validée par un garde de type dans `weekly-runs-api.ts` - le type `connectionInfo` y est déclaré
  trois fois (lignes 23, 34, 61) et devra suivre 37.4.

### Project Structure Notes

- `frontend/src/features/personal-runs/connection-details.tsx` (+ son test).
- `frontend/src/features/events/session-connection-gate.tsx:305-312`.
- `frontend/src/features/weekly-runs/weekly-run-card.tsx:75` et
  `frontend/src/features/weekly-runs/weekly-run-slot-page.tsx:675-682`.
- `frontend/src/features/weekly-runs/weekly-runs-api.ts:23,34,61` - le type du contrat.
- `api/src/Communications/Application/Email/SessionRunningEmail.php:44-66` - le corps du mail.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md]
- [Source: docs/archipelago-web-clients.md] - formes d'adresse, modes d'échec, note sur les tiers
- [Source: _bmad-output/implementation-artifacts/37-4-contrat-api-uri-wss-dans-connectioninfo.md]
- [Source: frontend/src/components/secret-field.tsx] - masquage par champ et copie (17.21/17.22)

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List

### Change Log

| Date | Change |
|------|--------|
| 2026-08-10 | Créée. Inventaire réel des surfaces : quatre panneaux, dont un `ConnectionPanel` local dupliqué dans `weekly-run-card`, là où l'epic n'en citait qu'un. Ajoute la décision à prendre sur les liens pré-remplis contenant le mot de passe. |
