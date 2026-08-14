# Story 37.4: Contrat API - l'URI wss dans connectionInfo

**Status:** implémentée - nom d'hôte tranché (`archilan.fr`, 2026-08-14) ; forme d'affichage à confirmer par 37.6
**Epic:** 37 - Accès WSS aux serveurs Archipelago
**Date:** 2026-08-10
**Dépend de :** 37.6 (forme d'adresse à exposer) et 37.1-37.3 (l'adresse doit être vraie avant
d'être annoncée).

## Story

En tant que joueur,
je veux que l'API me donne l'adresse chiffrée de ma run, et pas seulement un couple hôte/port brut,
afin que l'UI et les mails puissent m'envoyer vers un client desktop **ou** un client web sans que
j'aie à reconstruire l'adresse moi-même.

## Context

Le contrat actuel est un couple hôte/port, propagé identiquement dans trois contextes :

| Contexte | Point de sortie | Forme |
|---|---|---|
| Sessions | `SessionQuery::findById()` | `'host' => ..., 'port' => ...` |
| PersonalRuns | `PersonalRunDrafts` | `connectionHost`, `connectionPort`, `connectionPassword` |
| WeeklyRuns | `WeeklyEntry::getConnectionInfo()`, `DbalCurrentWeeklyRunsQuery` | `array{host, port, password}` |
| Communications | `SessionRunningMessage` / `SessionRunningEmail` | `Hôte : …` / `Port : …` en texte |

Aucun de ces points ne surface d'adresse chiffrée. Une fois 37.1-37.3 livrées, l'adresse réellement
utile au joueur est `wss://{hôte}:{port}` - et pour une partie des clients web, le couple séparé
reste nécessaire (voir 37.6). Les deux doivent coexister.

### Décision à prendre au démarrage de la story : quel nom d'hôte

L'epic a écrit `wss://archilan.fr:{port}`. Or ce que l'API surface aujourd'hui vient de
`RUNNER_PUBLIC_HOST`, qui vaut **`orchestrateur.archilan.fr`** en production
(`.env.prod.example:14`, injecté dans `SessionOrchestrator`, `SessionLifecycleManager` et
`OrchestratorWeeklyRunnerGateway` via `services.yaml:105,180,184`).

Les deux marchent : le port ne participe pas à la validation d'un certificat, et un certificat
existe déjà pour chacun des deux noms via le resolver letsencrypt. Le choix doit être **explicite**,
car il doit correspondre exactement à trois choses : le `domains` du routeur TLS de 37.2, le nom
utilisé sur le banc de 37.6, et la chaîne affichée en 37.5.

Recommandation : **conserver `RUNNER_PUBLIC_HOST`**. C'est le nom qui désigne déjà la machine des
runs, il évite d'introduire une variable de plus, et il garde le sens de « où tourne la run » séparé
de « où est le site ». Si le choix se porte sur `archilan.fr`, alors 37.2 doit demander ce nom dans
son bloc `domains` et la story doit le dire.

## Acceptance Criteria

### Construction de l'URI

1. Un composant unique construit l'URI de connexion à partir d'un hôte injecté et du port de la run.
   Il est **pur** et testable sans base ni HTTP.
2. La forme exacte produite est celle conclue par 37.6, verbatim. Si 37.6 conclut à plusieurs
   formes, le contrat les expose toutes, chacune sous un nom explicite - pas une seule chaîne
   « à retoucher par le client ».
3. Le nom d'hôte vient de la configuration, jamais d'une constante en dur, et jamais d'un
   `$_SERVER` ou d'une variable globale.
4. **L'URI n'est ni stockée ni persistée.** Elle est dérivée à la lecture. Une run dont le port
   change (relance) ne doit pas pouvoir exposer une adresse périmée.

### Propagation dans les trois contextes

5. `SessionQuery::findById()` expose l'adresse chiffrée **en plus** de `host` et `port`, qui restent
   pour l'admin et le diagnostic.
6. La charge utile PersonalRuns (`PersonalRunDrafts`) expose l'adresse chiffrée aux mêmes conditions
   de visibilité que les champs existants : seulement quand la run est active, et sans élargir ce
   qu'un non-propriétaire peut voir.
7. Le `connectionInfo` des WeeklyRuns expose l'adresse chiffrée dans les deux chemins qui le
   produisent - l'entité et la query DBAL - **avec la même valeur pour la même run**. Deux chemins,
   une seule vérité : c'est le piège de cette story.
8. Les doubles de test (`NullWeeklyRunnerGateway`, `SpyWeeklyRunnerGateway`) restent cohérents avec
   le nouveau contrat.
9. `SessionRunningMessage` transporte de quoi reconstruire l'adresse côté mail sans requête
   supplémentaire.

### Contraintes d'architecture

10. Aucune entité de domaine ne connaît le nom d'hôte public : la dérivation vit dans la couche
    Application. `WeeklyEntry` continue de ne stocker que ce qu'il stocke déjà.
11. Aucun contrôleur ne construit l'URI lui-même (`api/CLAUDE.md`, AC-P3 : désérialiser, valider,
    appeler un service, sérialiser).
12. `composer gates` passe. Les tests fonctionnels existants qui assertent la forme de
    `connectionInfo` sont mis à jour : `CurrentWeeklyRunsTest`, `WeeklyRunLaunchTest`,
    `LaunchWeeklyEntryTest`, `WeeklyEntryTest`, `SessionRunningHandlerTest`.

## Tasks / Subtasks

- [x] **Task 0**. Trancher le nom d'hôte : **aucun nouveau réglage**, l'URI est dérivée de l'hôte
  déjà stocké sur la run, qui vient de `RUNNER_PUBLIC_HOST` - la variable dont 37.2 tire aussi le
  domaine du certificat. Les deux ne peuvent donc pas diverger.
- [x] **Task 1** (AC 1-4). Écrire le composant de dérivation et ses tests unitaires.
- [x] **Task 2** (AC 5). Sessions.
- [x] **Task 3** (AC 6). PersonalRuns.
- [x] **Task 4** (AC 7-8). WeeklyRuns, les deux chemins exposés. *Les doubles de test renvoient un
  couple hôte/port et restent valides tels quels : ils alimentent l'écriture, pas la lecture.*
- [x] **Task 5** (AC 9). Communications : **rien à changer**, `SessionRunningMessage` transporte
  déjà hôte et port, donc de quoi construire l'adresse côté mail sans requête supplémentaire. Le
  corps du message appartient à 37.5.
- [x] **Task 6** (AC 10-12). Contrôle d'architecture, mise à jour des tests, `composer gates`.

## Dev Notes

- **Précédent à suivre :** `Shared/Application/Support/PublicMediaUrlResolver.php` fait exactement
  ce travail pour les médias - une base injectée, une méthode pure, aucun état. Le nouveau
  composant lui ressemble (`Shared/Application/Support/`), et rien n'oblige à le mettre dans
  Sessions : les trois contextes le consomment.
- **Deux chemins produisent le `connectionInfo` des WeeklyRuns.** `WeeklyEntry::getConnectionInfo()`
  lit l'entité, `DbalCurrentWeeklyRunsQuery` reconstruit le tableau depuis SQL (lignes 100 et 131,
  deux fois dans le même fichier). Si un seul des trois endroits est modifié, la même run affichera
  deux adresses différentes selon la page qui la lit - et rien ne le signalera.
- **Ne pas transformer le contrat en champ unique.** 37.6 existe précisément parce qu'il n'y a pas
  une chaîne qui marche partout : une famille de clients veut l'URI complète, l'autre veut hôte et
  port séparés. Remplacer `host`/`port` par une URI casserait la seconde famille et l'admin par la
  même occasion. **On ajoute, on ne remplace pas.**
- **Le port reste le port AP** (`bridgePort + AP_SERVER_PORT_OFFSET`, plage `35000-35099`), inchangé
  par cette story : elle habille une valeur qui existe déjà.
- **Story bloquée, mais pas entièrement.** Task 0 et la structure du composant peuvent être
  préparées avant 37.6 ; seule la forme exacte de la chaîne (AC 2) dépend de son résultat. Ne pas
  figer de forme « en attendant » dans le code : c'est ce genre de valeur provisoire qui survit.

### Project Structure Notes

- Nouveau composant : `api/src/Shared/Application/Support/` (voisin de `PublicMediaUrlResolver`).
- Sessions : `Application/Query/SessionQuery.php:48-60`.
- PersonalRuns : `Application/Service/PersonalRunDrafts.php:476-495`.
- WeeklyRuns : `Domain/Entity/WeeklyEntry.php:140-154`,
  `Infrastructure/Dbal/DbalCurrentWeeklyRunsQuery.php:100,131`,
  `Application/Command/LaunchWeeklyEntry.php:104`,
  `Infrastructure/Adapter/OrchestratorWeeklyRunnerGateway.php:58`,
  `Infrastructure/Double/{Null,Spy}WeeklyRunnerGateway.php`.
- Communications : `Application/Message/SessionRunningMessage.php:19-20`.
- Configuration : `api/config/services.yaml:105,180,184` pour `$runnerPublicHost`.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-37-acces-wss-aux-serveurs-archipelago.md]
- [Source: _bmad-output/implementation-artifacts/37-6-matrice-compatibilite-clients-web-tiers.md] - décide la forme d'adresse
- [Source: docs/archipelago-web-clients.md] - matrice et conclusion pour 37.4/37.5
- [Source: api/CLAUDE.md] - AC-A2, AC-A3, AC-P3, AC-P4

## Dev Agent Record

### Agent Model Used

claude-opus-5[1m]

### Completion Notes List

**Pourquoi la story n'a pas attendu 37.6.** 37.6 décide ce que l'**UI** affiche, pas si l'adresse
chiffrée existe. Le contrat expose maintenant les deux formes dont les deux familles de clients ont
besoin : le couple hôte/port (déjà là, conservé) **et** l'URI canonique `wss://{hôte}:{port}`. Il
n'y a rien de provisoire là-dedans - c'est la seule écriture possible d'une URI WebSocket sécurisée.
Si 37.6 révèle un client qui attend encore autre chose, ce sera un ajout d'affichage en 37.5, pas
une reprise de ce contrat.

**Aucun nouveau réglage.** L'URI dérive de l'hôte et du port **stockés sur la run**. Cet hôte vient
de `RUNNER_PUBLIC_HOST`, la variable dont 37.2 tire aussi le domaine du certificat : le nom annoncé
au joueur et le nom porté par le certificat sont donc le même par construction. Introduire une
variable dédiée aurait rendu la divergence possible, et cette divergence est invisible côté serveur
et fatale côté navigateur.

**Limite connue de cette dérivation :** l'hôte est figé au lancement de la run, alors que le domaine
du certificat est lu à chaud. Changer `RUNNER_PUBLIC_HOST` pendant qu'une run tourne laisserait
cette run avec une adresse périmée jusqu'à sa relance. Acceptable : le changement d'un nom d'hôte
public est une opération rare et planifiée.

**Écart assumé sur l'AC 7.** La story demandait de traiter « les deux chemins - l'entité et la query
DBAL ». Seule la query DBAL a été modifiée. `WeeklyEntry::getConnectionInfo()` est une méthode de
**Domaine** et n'a aucun consommateur applicatif : la seule référence hors de l'entité est son test
unitaire. Y injecter une dérivation d'adresse aurait fait entrer une préoccupation de présentation
dans le Domaine pour un chemin que personne n'emprunte. Les **deux chemins réellement exposés** sont
en revanche couverts, et un test vérifie qu'ils portent la même valeur dans la même réponse - c'est
le piège que la story nommait.

**Tests :**

- unitaires (nouveau) : forme produite, schéma toujours `wss`, et les cas où il n'y a pas d'adresse
  (hôte vide, port nul ou négatif). L'hôte vide compte : le stockage historique met une chaîne vide
  quand l'hôte est inconnu, et sans ce garde-fou l'API servirait `wss://:35042`, que le joueur
  copierait sans comprendre l'erreur ;
- fonctionnels : run personnelle active et inactive, run hebdomadaire, et l'égalité entre les deux
  chemins de production du `connectionInfo`.

**Gates :** `composer gates` vert (1801 tests).

### File List

- `api/src/Shared/Application/Support/ArchipelagoConnectionUri.php` (nouveau)
- `api/tests/Unit/Shared/ArchipelagoConnectionUriTest.php` (nouveau)
- `api/src/Sessions/Application/Query/SessionQuery.php` - `connectionUri`
- `api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php` - `connectionUri`
- `api/src/WeeklyRuns/Infrastructure/Dbal/DbalCurrentWeeklyRunsQuery.php` - `uri` dans les deux chemins
- `api/tests/Functional/PersonalRunLifecycleTest.php`, `api/tests/Functional/CurrentWeeklyRunsTest.php`

### Change Log

| Date | Change |
|------|--------|
| 2026-08-13 | Implémentation. Le contrat **ajoute** l'URI sans toucher au couple hôte/port : les deux familles de clients web sont servies. Aucun nouveau réglage - l'adresse dérive de l'hôte stocké, qui vient de la même variable que le domaine du certificat. |
| 2026-08-10 | Créée. Ajoute une décision explicite sur le nom d'hôte (`RUNNER_PUBLIC_HOST` = `orchestrateur.archilan.fr` aujourd'hui, contre `archilan.fr` annoncé par l'epic) et nomme le piège des deux chemins de production du `connectionInfo` WeeklyRuns. |
