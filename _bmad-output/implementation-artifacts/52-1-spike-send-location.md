# Story 52.1 - Spike : ouvrir un emplacement par le bridge

**Status:** review
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

- [x] **Task 1** (AC 1) - Room locale, essais manuels de `/send_location` via `!admin`, relevé des réponses.
- [x] **Task 2** (AC 2, 5) - Opération du bridge et ses tests.
- [x] **Task 3** (AC 3) - Essai de bout en bout, dont relance depuis la sauvegarde.
- [x] **Task 4** (AC 4) - Recommandation et mise à jour de 52.4 si besoin, Dev Agent Record.

## Dev Agent Record

### Réponses (lecture du code d'Archipelago 0.6.8 et essai sur une room locale)

Essai : image `archipelago:latest` (AP 0.6.8), deux slots Super Mario 64 « Bob le Bricoleur » (absent) et
« Alice » (présente), serveur avec mot de passe de room et mot de passe admin, séquence identique à celle du
bridge (`Connect` TextOnly, `!admin login`, puis la commande).

1. **`/send_location <joueur> <emplacement>` existe** (`ServerCommandProcessor._cmd_send_location`) et passe par
   `!admin` (`_cmd_admin` est `mark_raw`, la suite est rejouée telle quelle sur la console serveur). Elle accepte
   un **id d'emplacement numérique** : pas de recherche approximative sur le nom de l'emplacement.
2. **Réponses du serveur** :
   - **succès : aucun texte**. La preuve est le `PrintJSON` de type `ItemSend` diffusé à l'équipe, avec
     `item.player` = slot absent et `item.location` = emplacement, puis `ReceivedItems` chez le destinataire ;
   - **emplacement inconnu du slot ou déjà ouvert : rien du tout** (`register_location_checks` ignore en silence).
     Le bridge doit donc refuser ces deux cas lui-même, avant d'envoyer ;
   - **joueur introuvable** : `PrintJSON` de type `AdminCommandResult`, par exemple « Didn't find something that
     closely matches 'Nobody', did you mean 'Bob le Bricoleur'? (18% sure) ».
   - Le serveur découpe les commandes avec `shlex` : **un nom avec espaces doit être cité**
     (`'Bob le Bricoleur'`), sinon seul le premier mot est pris (« Bob » : refusé, constaté). Le nom exact donne
     un « Perfect Match ».
3. **Côté présent** : l'objet arrive comme un objet trouvé normalement (`ItemSend`, `ReceivedItems`), donc dans
   le feed du site par le chemin habituel.
4. **Côté absent** : à sa reconnexion, l'emplacement figure dans `checked_locations` ; ses autres emplacements, dont
   un qui contient son propre objet, restent dans `missing_locations`. Pas de doublon possible : une deuxième
   commande sur le même emplacement ne fait rien. Non vérifié en jeu réel (client mod C# notamment) : le client
   reçoit l'emplacement comme déjà ouvert, comme après un `!collect`.
5. **Sauvegarde** : après arrêt et redémarrage du conteneur, « Loaded save file with 1 received items », et
   l'emplacement est toujours ouvert pour l'absent.
6. **Plan B inutile** : la commande existe.

### Points découverts

- **Écho dans le chat** : `!admin <commande>` est rediffusé à tous comme message de chat de la connexion qui
  l'envoie (le mot de passe de `!admin login` est masqué). Chaque ouverture affichera donc « <slot du bridge>:
  !admin /send_location 'Bob le Bricoleur' 3626168 » dans les clients Archipelago et dans le feed du site (type
  `chat`). C'est déjà le cas des indices gratuits admin. À traiter dans 52.4 : masquer côté feed du site les
  lignes `!admin` émises par le bridge et publier à la place le message du Passeur.
- **Indices gratuits et noms avec espaces** : les commandes admin existantes du bridge (`/hint_location`,
  `/hint`, indices gratuits 9.28) passent le nom du joueur sans le citer. Pour un slot dont le nom contient un
  espace, le serveur ne prend que le premier mot : indice refusé, ou donné au mauvais joueur si un autre nom s'en
  approche. Hors périmètre de ce spike, à corriger à part (même `shlex.quote`).
- `mypy` du bridge signale sous Windows `asyncio.open_unix_connection` absent
  (`adapters/reachable_socket.py`) : erreur déjà présente sur master, propre à la plateforme.

### Livré

- Bridge (`Archipelago-Bridge`, branche `feature/epic-52-story-1-spike-send-location`) :
  - `ArchipelagoClient.send_location(slot, location_id)` : refuse avant envoi (déconnecté, slot inconnu,
    emplacement hors du monde du slot, déjà ouvert), envoie `!admin /send_location <nom cité> <id>`, attend
    l'`ItemSend` correspondant (10 s), lit un `AdminCommandResult` comme un refus. Un verrou ne laisse qu'une
    ouverture en cours, pour que la réponse du serveur ne puisse appartenir qu'à elle ;
  - route `POST /slots/{slot}/locations/{location_id}/send` (jeton interne) : 200 avec emplacement, objet et
    destinataire ; refus `{"code", "message"}` en 503, 404, 409, 422 ou 504 ;
  - tests `tests/test_send_location.py` (6).
- Aucun changement du site ni du client PHP : 52.4 appellera la route comme `SendBridgeCommand` appelle
  `/commands` (`BridgeEndpoint::url`).

### Gates

- Bridge : pytest 269 tests OK, ruff OK, mypy : seule l'erreur Windows préexistante.
