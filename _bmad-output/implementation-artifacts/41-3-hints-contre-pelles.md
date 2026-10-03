# Story 41.3: Hints contre pelles

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant que joueur bloqué,
je veux acheter un hint d'objet ou de lieu avec mes pelles quand ma partie le permet,
afin de me débloquer sans attendre d'avoir assez de points de hint Archipelago.

En tant qu'admin ou propriétaire d'une partie,
je veux choisir si les hints contre pelles sont permis et à quel prix,
afin d'adapter l'économie à la partie (compétitive, détendue, événement).

## Contexte

Premier puits des pelles. Le site sait déjà demander un hint au serveur Archipelago :
`POST /api/v1/sessions/{id}/slots/{n}/hints/request-item` (et sa variante par lieu) passe par le bridge, en payant avec
les points de hint du slot ou, pour un admin, gratuitement (commande admin `/hint` du bridge,
`bridge/core/rest_hints.py`). Un achat en pelles = débit des pelles, puis hint gratuit demandé au bridge, puis
remboursement si le hint échoue. **Aucun changement du bridge ni du client PHP du bridge.**

**Décisions 1 et 2 de l'epic (Jean, 2026-10-03)** : un prix par défaut sur le site, réglable par partie, un prix
pour un hint d'objet et un autre pour un hint de lieu ; désactivés par défaut dans les parties privées (le
propriétaire peut les activer), réglés par l'admin pour les hebdos et les events.

## Critères d'acceptation

### Réglage

1. Trois réglages de partie rejoignent la configuration de session (epic 27, profil par type + surcharge par
   partie) : **hints contre pelles** (oui/non), **prix d'un hint d'objet**, **prix d'un hint de lieu** (entiers de
   1 à 1 000). Défaut des trois profils : désactivé, objet 20, lieu 10. L'admin règle les profils (hebdos, events,
   privées) ; le propriétaire d'une partie privée peut les surcharger pour sa partie, comme les autres réglages
   qu'il a déjà. Ces réglages ne sont pas envoyés au serveur Archipelago.

### Achat

2. Endpoint `GET /api/v1/sessions/{id}/slots/{n}/pelle-hints` (joueur du slot) : réglage effectif, prix, et ce que
   le joueur peut payer (solde de pelles d'event de l'événement de la session, solde en or).
3. Endpoint `POST /api/v1/sessions/{id}/slots/{n}/pelle-hints` `{kind: "item"|"location", itemName|locationId,
   requestId}`, ouvert au propriétaire du slot (même règle que l'achat en points, story 9.31) :
   - refusé si la session n'est pas en cours (409), si les hints contre pelles sont désactivés (403) ;
   - **pelles d'event d'abord** : sur une session d'événement, si le solde de pelles de cet événement couvre le
     prix, il paie ; sinon les pelles en or ; sinon refus (422, avec les soldes) ;
   - débit par `RecordPelleMovement` (motif `hint_purchase`, clé `hint:{requestId}`), puis hint gratuit demandé au
     bridge ; un double envoi ne débite pas deux fois et ne redemande pas le hint ;
   - **si le hint échoue** (bridge injoignable, objet inconnu, lieu déjà trouvé), le joueur est remboursé (motif
     `hint_refund`, clé `hint-refund:{requestId}`) et reçoit l'erreur.
4. Le libellé du mouvement dit ce qui a été acheté (« Hint : Grappin » / « Hint de lieu : … »).

### Interface

5. Là où un joueur demande déjà un hint sur une session (fiche de slot d'une run privée), à côté du prix en points :
   un bouton « {prix} pelles » quand la partie le permet, désactivé avec une explication si le solde ne suffit
   pas ; confirmation avant achat ; le solde affiché se met à jour.
6. Réglages : les trois champs dans la page admin des profils de session et dans la surcharge d'une partie
   (admin et propriétaire d'une run privée).
7. Les motifs `hint_purchase` et `hint_refund` ont un libellé (historique, statistiques).

### Qualité

8. Gates : `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Champs de configuration (valeur, surcharge, profils par défaut) ; tests unitaires.
- [x] **Task 2** (AC 2-4) - Port `PelleHintGateway` (Application) et son adaptateur bridge (Infrastructure), commande
  d'achat, endpoints ; tests fonctionnels (paiement event puis or, refus, remboursement, idempotence).
- [x] **Task 3** (AC 5-7) - Front : bouton d'achat, réglages, libellés ; tests.
- [x] **Task 4** (AC 8) - Gates.

## Notes techniques

- La commande d'achat vit dans Sessions (elle connaît la session, le slot et le bridge) et appelle `Wallet` par
  `RecordPelleMovement` : Sessions dépend de Wallet, jamais l'inverse.
- L'appel au bridge se fait hors de la transaction du débit (le débit est committé avant) ; le remboursement est
  une nouvelle ligne, jamais une annulation.
- Une session d'événement se reconnaît à son `event_id` qui désigne un événement (celui d'une run privée porte
  l'id de la run, story 41.2).

## Dev Agent Record

- Réglages : `pelleHints`, `pelleItemHintPrice`, `pelleLocationHintPrice` sur `SessionServerConfig` (hors
  `toServerFlags`), la surcharge et le format canonique ; un profil stocké avant la story lit les défauts.
- `PelleHintGatewayInterface` (port Sessions) et `BridgePelleHintGateway` (appel gratuit du client bridge
  existant, `packages/` non modifié) ; `SpyPelleHintGateway` en test.
- `PelleHintTerms` (profil privé + surcharge de la run, ou profil event + surcharge de la session),
  `BuyHintWithPelles`, `PelleHintOfferQuery`, `PelleHintController` (`GET|POST /api/v1/sessions/{id}/slots/{n}/pelle-hints`).
- Front : option « N pelles » dans la confirmation du bouton d'indice (`HintConfirm`), branchée sur la fiche de slot
  d'une run privée ; réglages dans la page des profils et le formulaire de surcharge ; libellés des motifs.

- **Le hint gratuit du bridge ne dit pas s'il a créé un hint** (`send_admin_command` n'attend rien) : l'adaptateur
  lit la liste des hints du slot avant (un hint déjà ouvert ou un objet déjà trouvé n'est pas vendu) et après la
  demande (jusqu'à 2 s, 8 lectures) ; sans hint ouvert, `HintNotGivenException` et remboursement, avec la raison
  dans le message (`already_hinted`, `already_found`, `no_hint_created`). Testé par `BridgeHintEvidenceTest` ; à
  confirmer sur une vraie partie que la liste des hints du bridge reflète bien un hint admin dans ce délai.

### Hors périmètre, à reprendre

- **Hebdos** : leur fiche de slot passe par `/api/v1/weekly-runs/{runId}/entries/{entryId}/...` et leur config par
  le profil « weekly » du modèle ; l'achat en pelles n'y est pas branché.
- **Sessions d'événement** : l'API sait payer avec les pelles de l'événement, mais les joueurs n'ont pas de fiche
  de slot avec des indices (seule la page admin en a une) ; le bouton viendra avec cette page.
