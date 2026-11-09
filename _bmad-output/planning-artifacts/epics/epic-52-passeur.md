# Epic 52: Le Passeur

**Statut :** validé par Jean le 2026-11-09 (valeurs par défaut, refus, release, parties privées)
**Date :** 2026-11-09
**Origine :** échange avec Jean le 2026-11-09. À une LAN, des joueurs arrivent le dimanche, d'autres jouent le
vendredi et le samedi mais repartent avant le dimanche. Un joueur présent reste bloqué parce que l'objet qui le
débloque est dans le monde d'un joueur absent.

## Constat

La génération Archipelago ne sait pas qui joue quand : elle répartit les objets sans tenir compte des
disponibilités. Retoucher les YAML (`exclude_locations`, `local_items`, `progression_balancing`) réduit le risque
sans le supprimer, au prix d'échecs de génération et d'un réglage jeu par jeu.

Deux cas, très différents :

- **Le joueur qui part tôt.** Un `release` à son départ envoie à leurs destinataires tous les objets restés dans
  son monde. Il ne gêne plus personne. Le mécanisme existe, il n'est simplement pas assisté.
- **Le joueur qui arrive tard.** Tant qu'il n'est pas là, son monde est fermé. Un `release` le viderait avant
  qu'il ait joué. Rien n'existe aujourd'hui.

## L'idée

Le serveur Archipelago peut ouvrir un emplacement à la place d'un joueur (commande admin `/send_location <joueur>
<emplacement>`) : l'objet part chez son destinataire comme si le coffre avait été ouvert, l'emplacement est marqué
comme ouvert, rien n'est envoyé en double ensuite. On ne touche à rien d'autre du monde.

**Le Passeur**, c'est la plateforme qui repère qu'un joueur présent est bloqué par un objet situé chez un absent, et
qui lui propose d'ouvrir **ce seul coffre**, contre des **pelles d'argent** de l'événement, ou des **pelles de
bronze** de la partie en partie privée.

## Ce qui existe déjà

| Besoin | Existant |
|---|---|
| Savoir qu'un joueur est bloqué | `SlotBlockTracker`, sortie de BK notifiée (epic 40) |
| Savoir où est l'objet qui le débloque | multidata et spoiler (`read_multidata.py`), suivi exact `reachable.py` (sphères) |
| Savoir qui est là | présence par slot (Join/Part lus par le bridge) |
| Envoyer une commande admin au serveur | bridge (`send_admin_command`), `SendBridgeCommand` |
| Faire payer en pelles, rembourser si échec | `BuyHintWithPelles` (débit, commande admin, remboursement), `RecordPelleMovement` |
| Pelles d'argent d'un événement | `PelleKind::Event`, distribution et expiration (epic 41) |
| Annoncer | feed de la partie, `Notifier`, push |
| Demander les disponibilités | sondages (epic 51), mais réponses libres, non exploitables par une machine |

Il manque : les disponibilités sous une forme exploitable, le calcul « quel coffre ouvrir, chez qui », la commande
`/send_location` passée par le bridge, et l'achat lui-même.

## Principes

- **Un coffre, pas un monde.** Le Passeur ouvre un seul emplacement par achat : celui qui contient l'objet de
  progression qui débloque l'acheteur.
- **Seulement chez un absent.** Le monde visé appartient à un joueur absent (voir « Absent »). Un joueur présent
  mais lent n'est jamais court-circuité : on lui achète un indice, pas son coffre.
- **Seulement pour l'acheteur.** L'objet ouvert est destiné à l'acheteur. Le Passeur n'ouvre jamais un emplacement
  dont l'objet appartient à l'absent lui-même : sa progression reste intacte.
- **Deux terrains : événement et partie privée.**
  - En événement : **pelles d'argent seulement, pas d'or en secours** (contrairement aux indices). Sinon un ancien
    riche en or achète sa progression. Le Passeur est un outil de l'événement, à armes égales.
  - En partie privée : **pelles de bronze** de la partie, comme les indices et les primes.
  - Jamais en hebdo.
- **Désactivé par défaut**, activé par l'orga sur l'événement, par le propriétaire sur une partie privée, avec ses
  réglages.
- **L'absent ne peut pas refuser** : participer à un événement ou à une partie où le Passeur est actif vaut accord.
  Il est prévenu (notification) et voit tout ce qui a été ouvert (52.5).
- **Débit, commande, remboursement** : même circuit que l'achat d'indice. Si le serveur refuse ou ne répond pas, les
  pelles reviennent par une ligne de remboursement.

## Absent

**En événement**, un joueur est **absent** pour le Passeur quand les deux conditions sont vraies :

1. **Hors de ses créneaux déclarés** (pas encore arrivé, ou déjà parti) ;
2. **Aucun client connecté** sur son slot (présence du bridge).

Un joueur hors créneau mais connecté n'est pas absent : il est là, plus tôt que prévu. Un joueur sans créneau
déclaré n'est jamais absent : on ne devine pas une absence.

**En partie privée**, il n'y a pas d'inscription ni de créneaux : un joueur est absent quand **aucun client n'est
connecté sur son slot depuis un délai** réglé par le propriétaire (par défaut 2 h). Un slot qui ne s'est jamais
connecté depuis le lancement compte dès que ce délai est écoulé.

## Prix et partage (validés par Jean le 2026-11-09)

- **Prix de base** fixé par l'orga sur l'événement. Par défaut : 3 fois le prix d'un indice d'objet.
- **Prix dégressif avec l'attente** : plein tarif au début du blocage, puis une baisse par palier (par défaut
  10 % toutes les 15 minutes) jusqu'à un plancher (par défaut 40 % du prix de base). On évite l'achat réflexe, et
  on ne punit pas celui qui attend depuis une heure.
- **Part de l'absent** : un pourcentage du prix **payé** (par défaut 50 %) est versé à l'absent, dans la monnaie
  de l'achat (argent du même événement, ou bronze de la même partie). Calculée sur le prix payé, elle suit la dégressivité et ne crée jamais de pelles.
  L'autre part est détruite.
- **Limite** : un nombre maximal d'ouvertures par monde absent et par jour (par défaut 5), pour qu'un retardataire
  retrouve un monde à explorer.

## Stories

### 52.1 - Spike : ouvrir un emplacement par le bridge

En tant que développeur, je veux vérifier que `/send_location` existe dans la version d'Archipelago de notre image
et passe par `!admin` via le bridge, afin de savoir si le Passeur est faisable tel quel.

- Room locale, deux slots, ouverture d'un emplacement du slot déconnecté, vérification côté destinataire (objet
  reçu), côté absent à sa connexion (emplacement marqué ouvert, pas de doublon), et dans le feed.
- Sortie attendue : un endpoint du bridge (ou l'usage de `/commands`) et le format exact des réponses du serveur,
  pour distinguer un succès d'un refus.
- Dépôts concernés : `archilan-archipelago` (version d'AP), `Archipelago-Bridge`.
- Si la commande n'existe pas : plan B, le bridge se connecte comme le slot absent et envoie un `LocationChecks`.
- Détail : `implementation-artifacts/52-1-spike-send-location.md`.

### 52.2 - Créneaux de présence sur l'inscription

En tant que joueur inscrit, je veux indiquer quand j'arrive et quand je repars, afin que l'orga et le Passeur le
sachent.

- Une ou plusieurs plages (arrivée, départ) sur l'inscription, modifiables par le joueur et par l'admin, bornées par
  les dates de l'événement. Sans plage : présent tout l'événement.
- Saisie dans le parcours d'inscription et depuis la page de l'événement, comme l'étape « Questions » (51.5).
- Vue admin : qui est là à quel moment (frise par jour).
- Détail : `implementation-artifacts/52-2-creneaux-presence.md`.

### 52.3 - Le coffre qui débloque

En tant que plateforme, je veux savoir, pour un joueur bloqué, quels objets le débloquent et dans quels mondes ils
se trouvent, afin de proposer un coffre au Passeur.

- À partir du blocage (`SlotBlockTracker`), de la multidata (emplacement de chaque objet) et des objets déjà reçus,
  trouver les objets de progression du joueur qui le font avancer et qui sont dans un monde absent.
- Plusieurs exemplaires d'un même objet (objets progressifs) : en proposer un seul.
- Plusieurs candidats : proposer celui qui débloque le plus d'emplacements chez l'acheteur (suivi exact).
- Lecture seule, testable sans serveur.
- Détail : `implementation-artifacts/52-3-coffre-qui-debloque.md`.

### 52.4 - Faire appel au Passeur (pelles d'argent ou de bronze)

En tant que joueur bloqué, je veux payer des pelles d'argent pour faire ouvrir le coffre d'un absent, afin de
reprendre ma partie.

- Proposition sur la page de partie du joueur : le joueur absent, l'objet attendu, le prix du moment.
- Achat : débit en pelles d'argent de l'événement (bronze de la partie en partie privée), `/send_location`, remboursement si échec, part de l'absent
  créditée. Nouvelles raisons du registre (achat, remboursement, part de l'absent).
- Réglages de l'événement et de la partie privée : activation, prix de base, dégressivité, plancher, part de
  l'absent, limite par jour ; délai d'absence en partie privée.
- Feed : « Le Passeur a ouvert un coffre du monde de Bob pour Alice ». Notification à l'absent.
- Détail : `implementation-artifacts/52-4-faire-appel-au-passeur.md`.

### 52.5 - Ce que le Passeur a ouvert

En tant que joueur absent, je veux voir ce que le Passeur a ouvert dans mon monde et ce que ça m'a rapporté, afin de
ne pas être surpris à mon arrivée.

- Encart à la connexion et sur la page de partie : emplacements ouverts, pour qui, pelles reçues.
- Journal admin de l'événement : toutes les ouvertures, avec acheteur, absent, emplacement, prix.
- Détail : `implementation-artifacts/52-5-ce-que-le-passeur-a-ouvert.md`.

### 52.6 - Départ assisté

En tant qu'orga, je veux qu'au départ déclaré d'un joueur on me propose de faire un `release` de son monde, afin que
personne ne reste bloqué par un parti.

- À l'heure de départ déclarée (52.2), si le slot n'a plus de client : proposition à l'admin d'un `release` en un
  clic, via le bridge. Jamais automatique sans confirmation.
- **Le joueur ne lance pas son `release`** : il le demande à un admin. Depuis sa page de partie, « Je pars »
  prévient les admins (notification), qui confirment le `release`.
- En partie privée : le propriétaire tient le rôle de l'admin pour sa partie.
- Détail : `implementation-artifacts/52-6-depart-assiste.md`.

### 52.7 - Carte des dépendances (avant l'événement)

En tant qu'orga, je veux voir, avant le lancement, qui risque d'attendre qui selon les créneaux, afin de régénérer
si c'est trop chargé.

- Simulation à partir du spoiler (sphères) et des créneaux : pour chaque joueur, les objets attendus dans des mondes
  absents à ce moment-là.
- Comparaison de plusieurs générations, la moins bloquante est retenue par l'admin.
- Détail : `implementation-artifacts/52-7-carte-des-dependances.md`.

## Dépendances

- 52.1 avant 52.4 : sans commande d'ouverture fiable, pas de Passeur.
- 52.2 avant 52.4, 52.6 et 52.7.
- 52.3 avant 52.4.
- 52.5 après 52.4.
- 52.6 ne dépend que de 52.2 : livrable tôt, utile seule.
- 52.7 est indépendante du reste et peut attendre.

## Décisions de Jean (2026-11-09)

1. Valeurs par défaut retenues : 3 indices, -10 % toutes les 15 min, plancher 40 %, part de l'absent 50 % du prix
   payé, 5 ouvertures par monde absent et par jour.
2. L'absent ne peut pas refuser le Passeur.
3. Le joueur ne lance pas son `release` : il le demande à un admin.
4. Le Passeur existe en événement (argent) et en partie privée (bronze).

## Hors périmètre (pour plus tard)

- Hebdos.
- Ajustement automatique des YAML selon les créneaux (`exclude_locations`, `progression_balancing`).
- Découpage automatique d'un événement en plusieurs multiworlds par créneau.
