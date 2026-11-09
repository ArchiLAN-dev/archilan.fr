# Story 52.1 - Spike : ouvrir un emplacement par le bridge

**Status:** ready-for-dev
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-52-story-1-spike-send-location` (monorepo, depuis `develop`) ; branches homonymes dans
`Archipelago-Bridge` (et `archilan-archipelago` si la version d'AP doit bouger)
**Dépend de:** rien.

## Story

En tant que développeur,
je veux vérifier qu'on peut ouvrir un emplacement précis du monde d'un joueur déconnecté, depuis le bridge,
afin de savoir si le Passeur est faisable tel quel et sous quelle forme.

## Contexte (cartographie du 2026-11-09)

- Le bridge envoie déjà des commandes admin : `ArchipelagoClient.send_admin_command` (`bridge/core/ap_client.py`,
  `!admin login` puis `Say`), et `POST /commands` (`bridge/core/rest_session.py`), appelé par
  `api/src/Sessions/Application/Command/SendBridgeCommand.php`.
- L'achat d'indice en pelles (41.3) passe par la commande admin `/hint` : modèle pour lire la réponse du serveur et
  distinguer succès et refus (`run_self_hint`, `SelfHintOutcome`).
- Le bridge connaît déjà tous les placements (`ap_client._placements`, `GET /slots/{slot}/items/missing`).
- Commande visée côté serveur Archipelago : `/send_location <joueur> <emplacement>` (console serveur, accessible par
  `!admin`). Son existence dans la version de notre image est **à vérifier**, c'est l'objet du spike.

## Questions à trancher

1. `/send_location` existe-t-il dans la version d'Archipelago de l'image `archilan-archipelago`, et passe-t-il par
   `!admin` ?
2. Quel texte renvoie le serveur en cas de succès, d'emplacement inconnu, d'emplacement déjà ouvert, de joueur
   inconnu ? (Noms d'emplacement avec espaces, apostrophes, accents.)
3. Côté destinataire : l'objet arrive-t-il comme un objet normal (`ReceivedItems`, feed du site, `PrintJSON`) ?
4. Côté absent, à sa connexion : l'emplacement est-il marqué ouvert (`checked_locations`), sans doublon ? Le noter
   pour 2 ou 3 jeux de référence (dont un jeu à client mod C#).
5. La sauvegarde du serveur (`.apsave`) garde-t-elle l'ouverture après un redémarrage (relance depuis la sauvegarde,
   17.7) ?
6. Plan B si la commande manque : connexion du bridge comme le slot absent et envoi d'un `LocationChecks`. Coût,
   effets de bord (présence « connecté » visible, tags du client), à évaluer seulement si besoin.

## Critères d'acceptation

1. Les réponses aux questions 1 à 5 sont consignées dans le Dev Agent Record, avec les textes exacts du serveur.
2. Le bridge expose une opération dédiée, par exemple `POST /slots/{slot}/locations/{locationId}/send`, qui :
   - résout le nom du joueur et de l'emplacement à partir des ids (pas de nom saisi par l'appelant) ;
   - refuse un emplacement déjà ouvert (réponse explicite, pas d'appel au serveur) ;
   - renvoie un résultat typé : envoyé, déjà ouvert, refusé (avec le message), injoignable.
3. Une room locale à deux slots montre l'ouverture de bout en bout (objet reçu par le présent, emplacement marqué
   ouvert pour l'absent).
4. Si `/send_location` est absent : le spike s'arrête sur une recommandation chiffrée (plan B ou montée de version
   d'AP), et 52.4 est mise à jour avant d'être commencée.

## Tests et gates

5. Tests du bridge : résolution des noms, refus d'un emplacement déjà ouvert, lecture des réponses du serveur
   (succès, refus, délai dépassé), sur le modèle des tests de `run_self_hint`.
6. Gates du bridge verts ; pas de changement du site dans ce spike, sauf le client PHP si l'opération y est ajoutée
   (`packages/`, version à bumper avant tag, voir la publication du client).

## Tasks / Subtasks

- [ ] **Task 1** (AC 1) - Room locale, essais manuels de `/send_location` via `!admin`, relevé des réponses.
- [ ] **Task 2** (AC 2, 5) - Opération du bridge et ses tests.
- [ ] **Task 3** (AC 3) - Essai de bout en bout, dont relance depuis la sauvegarde.
- [ ] **Task 4** (AC 4) - Recommandation et mise à jour de 52.4 si besoin, Dev Agent Record.
