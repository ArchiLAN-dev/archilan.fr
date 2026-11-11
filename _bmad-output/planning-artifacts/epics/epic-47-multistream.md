# Epic 47 - Multistream des participants

**Date :** 2026-10-31
**Origine :** demande de Jean le 2026-10-31 : un moyen de proposer un multipov, ou la liste des streams en ligne sur
un événement, pour que la régie diffuse tout le monde pendant les pauses.

## Constat

- L'API sait déjà qui streame sur un événement : `GET /api/v1/events/{id}/participant-streams` (story 7.7) lit la
  chaîne Twitch des inscrits confirmés dans leurs liens sociaux, vérifie qui est en live en un appel Helix mis en
  cache, et renvoie la liste avec les lives en tête. Un événement terminé ne renvoie rien.
- Le site l'affiche déjà : sur la page de l'événement et sur `/streams/event/<id>`, une liste de cartes avec un seul
  lecteur partagé (un stream à la fois, derrière le consentement cookies de la story 7.5).
- Il manque deux choses : un écran que la régie met dans OBS pour montrer tout le monde à la fois, et une vue
  publique où plusieurs lives tournent côte à côte.

## Principes

1. **Chaîne renseignée = accord, refus possible.** Rediffuser un stream sur la chaîne ArchiLAN, c'est diffuser le
   contenu de quelqu'un d'autre. Un joueur qui a mis sa chaîne Twitch dans son profil est diffusable par défaut,
   mais peut refuser, inscription par inscription (décision de Jean le 2026-10-31). La vue
   publique, elle, montre le lecteur Twitch du joueur lui-même, comme la liste actuelle : pas de consentement en plus.
2. **On réutilise la lecture existante** (participant-streams) : pas de second appel à Twitch, pas de nouveau cache.
3. **L'écran régie suit le modèle de `/o/partenaires`** (épic 45) : une page sans habillage du site, pensée pour une
   source navigateur OBS, réglée par l'URL.
4. **Plafond de lecteurs.** Au-delà de 6 à 9 lecteurs Twitch simultanés, OBS et le navigateur chauffent : la
   mosaïque est plafonnée, le reste passe en rotation.

## Stories

### 47.1 - Le joueur accepte que la régie diffuse son stream

- Une case par inscription, cochée par défaut : « La régie ArchiLAN peut diffuser mon stream Twitch pendant
  l'événement ». Le joueur la décoche lui-même s'il refuse, tant que l'événement n'est pas terminé.
- La lecture participant-streams porte l'information ; une lecture dédiée à la régie renvoie les lives, sauf
  ceux qui ont refusé.
- L'admin voit qui a refusé sur la liste des inscriptions de l'événement.

### 47.2 - L'écran régie : tous les lives en mosaïque

- `/o/streams?event=<id>` : mosaïque automatique des lives (hors refus) (1, 2, 4, 6 ou 9 cases selon le nombre),
  pseudo sur chaque case, lecteurs muets, liste relue toutes les minutes.
- Option `?mode=rotation&duree=<s>` : un stream en grand, les autres en vignettes, qui tourne à intervalle régulier.
- Au-delà du plafond, les cases tournent par groupes.
- Plafond `?max=` (4 par défaut, jusqu'à 9). Rien à l'écran quand personne n'est en live (la régie garde sa scène).

### 47.3 - La mosaïque publique

- Sur `/streams/event/<id>`, un mode « Mosaïque » à côté de la liste : jusqu'à 4 lives côte à côte, un clic sur un
  stream le passe en grand avec le son.
- Même consentement cookies que la liste actuelle (story 7.5), pas de mosaïque sur téléphone.

## Dépendances

- 47.2 dépend de 47.1 (la lecture régie). 47.3 est indépendante.

## Hors périmètre (pour plus tard)

- Les streams des parties privées et des hebdos (la lecture existe pour eux, l'écran régie se limite aux événements).
- Le son géré par l'écran régie : c'est OBS qui gère le son de la scène.
- Un lien vers un service de multistream tiers (type multistre.am) : écarté au profit de nos propres écrans.

---

# Partie 2 - Diffusion locale par MediaMTX

**Date :** 2026-11-11
**Origine :** échange avec Jean le 2026-11-11 (NDI, puis « un serveur local MediaMTX + le serveur archilan
distant ? »). Les joueurs qui ne streament pas sur Twitch doivent pouvoir envoyer leur écran à la régie, sur le
réseau de la LAN, sans rien publier sur Internet.

## Constat

- L'écran régie `/o/streams` (47.2) ne montre que des lives Twitch : un joueur sans chaîne, ou qui ne veut pas
  streamer publiquement, n'y apparaît pas.
- Le NDI marche sur une LAN mais ne s'intègre pas au site : sources à monter à la main dans OBS, pas d'accès
  contrôlé, gros débit (100 à 150 Mbit/s par joueur en NDI complet).
- MediaMTX (serveur média libre, un binaire) reçoit en WHIP (WebRTC, OBS 30 et plus) ou SRT, se lit en WHEP
  (WebRTC) dans un navigateur, délègue l'authentification à une URL HTTP et expose la liste des flux par une API.

## Principes

1. **La vidéo ne quitte pas la salle.** MediaMTX tourne sur la LAN ; seuls l'autorisation de publier et l'état des
   flux passent par Internet, toujours à l'initiative de la LAN (le serveur distant ne peut pas joindre la LAN).
2. **Le site décide qui publie.** MediaMTX demande à l'API à chaque publication (`authMethod: http`) : inscription
   confirmée, événement en cours, diffusion non refusée (47.1), compte non banni. Aucune liste de joueurs sur la
   machine locale.
3. **Une clé par inscription, calculée** : `HMAC(secret, eventId | registrationId | version)`, réaffichable sans
   être stockée, invalidée par « Régénérer » (version + 1) et morte avec l'événement ou l'inscription.
4. **L'état est poussé en entier, pas en événements** : un agent sur la LAN envoie la liste complète des flux toutes
   les 10 à 15 s ; sans nouvelles depuis 30 s, tout le monde est hors ligne. Un envoi perdu se corrige tout seul.
5. **Même écran régie** : `/o/streams` mêle lives Twitch et flux locaux, même mosaïque, même rotation.
6. **Jamais public** : un flux local n'apparaît ni sur la mosaïque publique (47.3) ni sur la page de l'événement.
   Le texte du joueur dit clairement que la régie peut remettre ses images sur le live ArchiLAN.

## Architecture

```
 Joueur (OBS, WHIP ou SRT)                Régie (OBS, source navigateur)
        |                                   | 1. /o/streams + liste (HTTPS archilan.fr)
        v                                   |
 +--------------+   auth (HTTPS sortant)  +-+----------------+
 | MediaMTX LAN | ----------------------> | API archilan.fr  |
 +------+-------+                         +------------------+
        |  ^ agent : GET /v3/paths/list         ^
        |  +--------------------------------->--+ état des flux (HMAC, toutes les 10-15 s)
        |
        +--> 2. vidéo WHEP (WebRTC), uniquement sur la LAN, vers la régie
```

Nom HTTPS sur l'IP privée : `media.lan.archilan.fr` (ou un nom par événement), certificat Let's Encrypt par
challenge DNS-01, sans exposition publique. La page `/o/streams` étant en HTTPS, la lecture WHEP doit l'être aussi.

## Stories

### 47.4 - Spike : MediaMTX sur la LAN, de la clé à l'écran régie

Valider les quatre inconnues avant de construire : le jeton WHIP transmis à l'authentification HTTP, la lecture
WHEP depuis une page HTTPS publique vers une IP privée (certificat DNS-01, Private Network Access de Chromium, source
navigateur d'OBS), le débit et la latence avec plusieurs joueurs, et le format de l'API de MediaMTX pour l'agent.
Livre un dossier `ops/lan-media/` (configuration MediaMTX, compose, procédure du certificat) et une recommandation
qui met à jour 47.5 à 47.9. Story détaillée : `47-4-spike-mediamtx-lan.md`.

### 47.5 - Le serveur média d'un événement et la clé de publication

- Admin : champ « Serveur média local » sur l'événement (nom d'hôte HTTPS, vide = fonction absente) et secret de
  l'agent (généré, affiché une fois, régénérable).
- Clé de publication par inscription (principe 3) et chemin opaque dérivé de l'inscription (sans le pseudo).
- `POST /api/v1/media-auth` appelé par MediaMTX : vérifie l'action (`publish` seulement, la lecture est ouverte sur
  la LAN ou réglée par le spike), le chemin, la clé, l'événement en cours, l'inscription confirmée, la diffusion non
  refusée, le compte non banni. 200 ou 401, sans détail. Limitation de débit.
- Tests unitaires de la clé (stable, change avec la version, différente par inscription) et fonctionnels de
  l'endpoint (chaque cas de refus).
- Spike 47.4 : la clé ne contient jamais « : » (sinon MediaMTX la coupe en `user:password`) ; l'API la lit dans
  `token` (WHIP) puis `password` (SRT). Remplace l'endpoint d'essai et `MEDIA_AUTH_SPIKE_KEY`.

### 47.6 - L'agent de la régie et l'état des flux

- Petit agent (script ou conteneur dans `ops/lan-media/`) : lit l'API de MediaMTX toutes les 10 à 15 s et pousse la
  liste des chemins prêts à `PUT /api/v1/events/{id}/local-streams`, signé HMAC avec le secret de l'agent.
- L'API garde l'état (chemins en ligne, depuis quand, dernier envoi) ; hors ligne au-delà de 30 s sans envoi.
- Tests : signature (refus si fausse ou périmée), remplacement complet de l'état, péremption.
- Spike 47.4 : lire `online` / `onlineTime` (`ready` est déprécié en MediaMTX 1.21) ; un flux coupé sort aussitôt
  de la liste.

### 47.7 - « Diffuser pour la régie » : la carte du joueur

- Carte dans les outils de l'espace inscrit (53.3), seulement si l'événement a un serveur média, l'inscription est
  confirmée, la diffusion non refusée, de la veille à la fin de l'événement.
- WHIP en tête (serveur + jeton Bearer, boutons Copier, clé masquée par défaut), SRT en repli (URL complète, rappel
  B-frames à 0 : sinon l'écran régie ne peut pas lire le flux, constaté au spike 47.4), réglages conseillés, bouton
  « Régénérer ma clé ».
- État en direct : « La régie te reçoit » ou « En attente de ton flux » (lu sur l'état de 47.6).
- Guide d'aide « diffusion » (53.5) complété ; ligne du mail de la veille (53.8) : « tes réglages de diffusion sont
  dans ton espace », jamais la clé dans un mail.

### 47.8 - L'écran régie lit les flux locaux

- `broadcast-streams` renvoie deux sortes d'entrées : `twitch` (comme aujourd'hui) et `local` (pseudo, `whepUrl`
  construite depuis le serveur média de l'événement et le chemin).
- `/o/streams` : lecteur WHEP (WebRTC, muet, reconnexion automatique) pour les entrées locales, iframe Twitch pour
  les autres ; même mosaïque, même rotation, même plafond.
- La mosaïque publique (47.3) et la page de l'événement ignorent les entrées `local`.
- Spike 47.4 : partir de `features/streaming/whep-player.tsx`, qui coupe déjà un lecteur sans image depuis 3 s
  (WebRTC seul attendrait 25 s) ; retirer en plus la case dès que l'agent ne voit plus le flux.
  Plan B si la source navigateur d'OBS refuse l'accès au réseau local : écran servi depuis la LAN par un proxy.

### 47.9 - Le suivi côté admin

- Page des inscriptions : colonne « Diffusion locale » (jamais connecté, en live, coupé il y a X min).
- Hub de l'événement, groupe Jour J : encart « Serveur média » (agent vu il y a X s, nombre de flux, lien vers la
  procédure de `ops/lan-media/`).

## Dépendances

- 47.4 d'abord : ses conclusions peuvent changer 47.5 à 47.8 (protocole conseillé, forme du jeton, lecteur).
- 47.5 avant 47.6 et 47.7 ; 47.8 après 47.6 ; 47.9 en dernier.
- 47.7 s'appuie sur l'espace inscrit (epic 53).

## Hors périmètre (pour plus tard)

- **Internet coupé pendant la LAN** : les flux ouverts continuent, mais plus personne ne peut se connecter. Pistes
  : l'agent garde en cache les autorisations des dernières heures, ou une clé de secours locale. À trancher après
  une première LAN.
- Le son des flux locaux à l'écran régie (muet, comme la mosaïque Twitch).
- Le profil OBS à télécharger (zip à importer) : confortable, mais fragile selon les versions d'OBS.
- L'enregistrement des flux locaux par MediaMTX.
- NDI : écarté au profit de MediaMTX (pas d'accès contrôlé, débit, montage à la main).
