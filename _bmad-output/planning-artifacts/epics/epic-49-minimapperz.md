# Epic 49: Minimapperz - des pelles pour les parties jouées à la LAN

**Statut :** proposé le 2026-11-02
**Date :** 2026-11-02
**Origine :** échange avec Jean le 2026-11-02 - Minimapperz (minimapperz.com, geoguessr de jeux vidéo en 360°)
devient partenaire et nous ouvre une instance pour l'ArchiLAN #4. Idée : lire leurs scores en direct et donner des
pelles d'event aux joueurs selon leurs parties.

## Constat

Minimapperz expose une **API organisateur** en lecture seule (OpenAPI :
`https://backendv1-74040419982.us-central1.run.app/api/v1/organiser/openapi.json`, Swagger sur `.../docs`).
Testée le 2026-11-02 sur l'event de test `archilan-test` :

- Authentification : `Authorization: Bearer mmz_ev_...`, **une clé par event**, créée par l'organisateur sur la
  page admin de l'event Minimapperz. Mauvaise clé ou bonne clé sur un autre slug : `401 {"detail":"Invalid API key
  for this event"}`.
- Endpoints, tous sous `/api/v1/organiser/events/{slug}` :
  - `GET` : l'event (`slug`, `name`, `opens_at`, `closes_at`, `is_open`, `ranking_mode` parmi `best_game`,
    `total_points`, `one_format`). Sert de test de connexion.
  - `GET /players` : chaque joueur avec `username`, `code` (nullable), `joined_at`, `games`, `wins`,
    `total_points`, `rank`, `score`.
  - `GET /games?since=<date-time>` : les parties **terminées**, plus récentes d'abord, chacune avec `game` (uuid),
    `played_at`, `rounds` et pour chaque joueur `username`, `code`, `points`, `place`, `won`. `since` ne renvoie
    que les parties finies après cette date.
  - `GET /ranking` : le classement de l'event (`mode`, `format`, entrées `rank`, `username`, `code`, `score`,
    `games`, `wins`).
  - `GET /live` : flux SSE (`player_joined`, `game_started`, `round_ended`, `game_ended`, `ranking_changed`),
    `retry: 5000`, un ping toutes les 20 s. La doc prévient que la connexion peut couper : il faut se reconnecter
    et relire les snapshots.
- Aucun en-tête de limite de débit observé. Pas de pagination documentée (`data` + `count`).
- Pas de webhook : c'est à nous d'aller lire.

**Le lien de compte passe par `code`.** Jean dispose d'une liste de codes Minimapperz pour l'event ; le joueur
saisit son code en rejoignant la LAN sur Minimapperz, et l'API renvoie ce code dans chaque partie. Décision du
2026-11-02 : **ArchiLAN distribue les codes aux inscriptions** (option A), Jean importe la liste dans l'admin.

## Principes

- **Une source de pelles de plus, sur le modèle du blind test** (stories 41.40 et 41.54,
  `CreditBlindTestSource`, `BlindTestSettings`) : pelles **d'event** (`PelleKind::Event`, converties en or à la fin
  de l'event comme les autres), montants et plafond réglés **par l'event chez nous**, jamais déduits des points
  Minimapperz, clé d'unicité par crédit, plafond vérifié sous le verrou du ledger du membre. Écriture uniquement via
  `RecordPelleMovement`, nouvelle raison `PelleReason::Minimapperz`.
- **On lit, on n'écoute pas** : le crédit repose sur une collecte périodique de `GET /games?since=`, idempotente et
  rattrapable après une panne. Le flux SSE `/live` n'est pas utilisé côté API (connexion longue dans un worker PHP,
  coupures annoncées par leur doc).
- **Un code = une inscription**, attribué par le site, jamais saisi ni choisi par le joueur chez nous. Un joueur
  Minimapperz sans code, ou avec un code inconnu, ne reçoit rien automatiquement ; l'admin le voit et peut
  toujours créditer à la main (`AdjustMemberPelles`, déjà là).
- **La clé vit en base, avec l'event, en clair** (décision du 2026-11-02). Elle est limitée à un event, en lecture
  seule (pseudos, codes, scores) et révocable chez Minimapperz : une fuite exposerait un classement, rien de plus.
  Une variable d'env imposerait un redéploiement à chaque LAN et séparerait la clé de son slug ; un chiffrement
  demanderait une brique qui n'existe pas dans l'API, pour un gain nul. Garde-fous retenus : jamais renvoyée au
  front après saisie (champ en écriture seule, affichage « clé enregistrée, se termine par ...XXX »), jamais dans
  les logs, modifiable par un admin seulement, effaçable une fois l'event terminé. Un futur secret qui permet
  d'écrire ou de payer, lui, devra être chiffré.
- **Contexte `Wallet`**, comme le blind test : port `MinimapperzClientInterface` dans `Application/Port/`, client HTTP
  dans `Infrastructure/Http/`, double `StubMinimapperzClient` en `when@test`. Aucun appel réel à Minimapperz dans
  la suite de tests.
- **Le minimum utile** : pas de bonus de classement final automatique, pas d'affichage des points Minimapperz dans
  les profils. Ce qui n'est pas dans une story est hors périmètre.

## Stories

### 49.1 - Brancher un event sur Minimapperz (socle)

En tant qu'admin, je veux renseigner le slug et la clé Minimapperz d'un événement et régler le barème, afin que le
site puisse lire les parties de cet event.

- Réglage par event dans `Wallet`, à côté du blind test (`admin-event-pelles-page.tsx`) : activé oui/non, slug
  Minimapperz, clé API (écriture seule), **pelles par partie jouée**, **pelles en plus pour une victoire**
  (`won`), **plafond par joueur** pour l'event. Bornes et messages d'erreur sur le modèle de `BlindTestSettings`.
- Bouton « Tester la connexion » : appelle `GET /events/{slug}` et affiche le nom de l'event, ses dates et
  `is_open`, ou l'erreur (401 = clé ou slug invalide, autre = Minimapperz injoignable).
- Clé stockée en base avec le réglage de l'event, sans chiffrement (voir Principes). L'API admin renvoie seulement
  `apiKeySet` et les 3 derniers caractères ; un champ vide à l'enregistrement conserve la clé existante, un bouton
  « Effacer la clé » la supprime.
- Port `MinimapperzClientInterface` (`event`, `games(since)`, `players`, `ranking`) renvoyant des records typés,
  implémentation HTTP (`HttpClientInterface`, timeout court) et double de test.

### 49.2 - Les codes Minimapperz : import et attribution aux inscriptions

En tant qu'admin, je veux coller la liste de codes que Minimapperz m'a donnée, afin que chaque inscrit reçoive le
sien sans que j'aie à les distribuer.

- Admin de l'event : zone de texte « un code par ligne », import idempotent (doublons et lignes vides ignorés, un
  code déjà présent n'est pas recréé). Compteurs : codes importés, attribués, libres. Alerte si moins de codes
  libres que d'inscriptions sans code.
- Attribution : un code libre est attribué à une inscription **à la première ouverture** de sa page d'inscription
  (ou en masse par un bouton admin « attribuer à tous les inscrits »). Attribution atomique : deux inscriptions ne
  peuvent jamais recevoir le même code (contrainte unique en base + verrou).
- Page d'inscription du joueur : bloc « Minimapperz » avec son code, un bouton copier et une phrase d'explication
  (« saisis ce code en rejoignant la LAN sur Minimapperz pour gagner des pelles »), avec le lien de la LAN.
  Affiché seulement si l'intégration est activée sur l'event.
- Une inscription annulée **garde** son code (pas de réattribution automatique : le code a pu être déjà utilisé).
  L'admin voit le tableau code / inscrit et peut détacher un code à la main.

### 49.3 - Collecter les parties et créditer les pelles

En tant que joueur inscrit, je veux recevoir mes pelles d'event peu après chaque partie Minimapperz, afin de voir
mes trouvailles récompensées pendant la LAN.

- Tâche planifiée (Symfony Scheduler, `Schedule.php`) toutes les minutes environ, pour chaque event dont
  l'intégration est activée : `GET /games?since=<curseur - marge>`. Le curseur (plus grand `played_at` vu) est
  stocké par event ; la marge de recouvrement (quelques minutes) absorbe les décalages d'horloge, les doublons étant
  neutralisés par la clé d'unicité.
- Pour chaque joueur de chaque partie dont le `code` correspond à une inscription de l'event : un mouvement
  `PelleReason::Minimapperz`, clé d'unicité `minimapperz:{game}:{code}`, montant = pelles par partie + bonus si
  `won`, libellé lisible dans l'historique (« Minimapperz - partie gagnée »). Plafond : la partie qui le dépasse
  est créditée jusqu'au plafond seulement, puis plus rien.
- La collecte s'arrête quand l'event ArchiLAN est terminé ; une dernière passe a lieu **avant** la conversion des
  pelles d'event en or (vérifier l'ordre avec `DistributeEventPelles` / `ExpireEventPelles`).
- Erreurs : un 401 désactive la collecte de l'event et l'affiche en admin (clé révoquée) ; une erreur réseau ou 5xx
  est journalisée (`LoggerInterface`) et retentée au cycle suivant, sans avancer le curseur.
- Admin : dernière collecte (date, nombre de parties lues, pelles créditées), et la liste des **joueurs non
  reliés** (`username` vu sans code, ou avec un code inconnu) pour un crédit manuel au besoin.

### 49.4 - L'écran Minimapperz : classement en direct pour la régie

En tant qu'organisateur, je veux afficher le classement Minimapperz de la LAN dans OBS et sur l'écran de la salle,
afin que les joueurs et les viewers suivent la compétition.

- Route publique dans le groupe `(overlay)`, `/o/minimapperz?event=<slug>`, sur le modèle de `/o/partenaires` et
  `/o/streams` : classement (`GET /ranking`), dernières parties, logo Minimapperz (partenaire de l'epic 45).
- Le front ne parle jamais à Minimapperz (la clé reste côté API) : endpoint public de notre API qui relaie le
  classement, avec un cache court (15 à 30 s) pour ne pas multiplier les appels quel que soit le nombre d'écrans.
- Affichage du pseudo ArchiLAN quand le code est relié à une inscription, du `username` Minimapperz sinon.
- Modes overlay (fond transparent) et écran, rafraîchissement automatique ; lien prêt à copier dans l'admin de
  l'event.

## Dépendances

- 49.1 avant toutes les autres.
- 49.2 avant 49.3 (sans codes attribués, aucun joueur n'est relié).
- 49.4 ne dépend que de 49.1 ; l'affichage des pseudos ArchiLAN arrive avec 49.2.
- Tout doit être en prod avant l'ArchiLAN #4, avec un essai complet sur l'event de test `archilan-test`.

## Questions ouvertes (à confirmer avec Minimapperz)

1. **Un code est-il à usage unique ?** Si deux comptes peuvent rejoindre la LAN avec le même code, le lien ne
   prouve plus rien : il faudrait alors fixer le `username` au premier usage du code et ignorer les autres.
2. Le `username` peut-il changer pendant l'event ?
3. `since` filtre-t-il sur la fin de la partie, et `played_at` est-il bien cette fin ?
4. Y a-t-il une limite de débit (on vise 1 appel par minute pour la collecte, plus le relais du classement) ?
5. Les payloads réels n'ont pas encore été vus (event de test vide au 2026-11-02) : jouer deux parties de test avec
   deux codes avant de figer 49.3.

## Hors périmètre

- Bonus de classement final automatique (podium de l'event) : crédit manuel par l'admin si besoin.
- Consommation du flux SSE `/live` (animations « partie terminée » en direct sur l'écran).
- Codes pour les membres non inscrits à l'event (le blind test l'autorise, 41.54 ; ici les codes sont en nombre
  limité et vont aux inscriptions).
- Toute écriture vers Minimapperz (l'API est en lecture seule).
