# Serveur média de la LAN

Le serveur média permet aux joueurs d'envoyer leur écran à la régie par le réseau de la LAN, sans rien publier sur
Internet (epic 47, partie 2). Ce dossier a été créé par le spike 47.4. Depuis la story 47.5, chaque inscrit confirmé
a sa propre clé.

## Fonctionnement

- **MediaMTX** tourne sur une machine de la LAN, en général la régie. Il reçoit les flux des joueurs :
  - en WHIP, depuis OBS 30 ou plus récent ;
  - en SRT, en repli.
- **Avant chaque publication**, MediaMTX demande l'autorisation au site (`POST /api/v1/media-auth`). Le site répond
  200 ou 401. Seule cette question passe par Internet. Le site accepte un inscrit confirmé, qui n'a pas refusé la
  diffusion et dont le compte n'est pas banni, avec sa clé du moment, de 24 h avant le début à la fin de
  l'événement.
- **Le serveur média de l'événement** se règle dans l'admin, sur la fiche de l'événement : bloc « Diffusion locale »,
  champ « Serveur média local » (le nom d'hôte, par exemple `media.lan.archilan.fr`). Vide : pas de diffusion
  locale.
- **Chaque inscrit** trouve son chemin `p-...`, sa clé et les adresses à coller dans OBS dans son espace de
  l'événement (story 47.7). La clé est calculée à partir d'`APP_SECRET`, jamais stockée : changer `APP_SECRET`
  invalide toutes les clés. Le joueur peut régénérer la sienne, l'ancienne cesse aussitôt de marcher.
- **La régie lit les flux en WHEP (WebRTC)**, uniquement sur la LAN. Cette lecture ne demande rien au site : si
  Internet tombe, les flux déjà ouverts restent lisibles.
- **Les chemins** ont la forme `p-<identifiant>`. Tout autre chemin est refusé par MediaMTX lui-même.
- **L'agent de la régie** (`agent.py`, story 47.6) lit toutes les 10 s les flux en ligne sur l'API locale de
  MediaMTX et en envoie la liste au site, signée avec le secret de l'agent de l'événement. Le site sait ainsi qui
  diffuse. Sans nouvelles de l'agent pendant 30 s, le site ne compte plus aucun flux comme en direct.

## Installation sur la machine de régie (Linux)

1. Installer Docker et le plugin compose.
2. Copier ce dossier, puis `cp .env.example .env` et renseigner :
   - `MTX_AUTHHTTPADDRESS` : `https://archilan.fr/api/v1/media-auth` ;
   - `MTX_WEBRTCADDITIONALHOSTS` : l'IP LAN fixe de la machine, par exemple `192.168.1.10` ;
   - les trois lignes `ARCHILAN_*` de l'agent : dans l'admin du site, fiche de l'événement, bloc « Diffusion
     locale », bouton « Configuration de l'agent » (visible une fois le serveur média enregistré). Une configuration
     par événement : à refaire pour chaque LAN.
3. Mettre le certificat dans `certs/` (voir plus bas) : `fullchain.pem` et `privkey.pem`.
4. `docker compose up -d`, puis `docker compose logs -f`. L'agent écrit une ligne à chaque changement, par
   exemple `3 en direct, 0 ignorés`, et dit si MediaMTX est injoignable ou si le site refuse l'envoi (secret faux,
   horloge de la machine décalée de plus de 2 minutes).
5. Côté site : renseigner « Serveur média local » sur l'événement dans l'admin.

Le compose utilise le réseau de l'hôte (`network_mode: host`), pour que WebRTC annonce les vraies adresses de la
machine. Docker Desktop (Windows, macOS) ne gère pas ce mode comme Linux : pour un essai sur un poste de dev, voir
« Essai local » plus bas.

## Ports à ouvrir sur la LAN (pare-feu de la machine)

| Port | Protocole | Rôle |
|---|---|---|
| 8889 | TCP | WebRTC : signalisation HTTPS (WHIP et WHEP) |
| 8189 | UDP | WebRTC : médias |
| 8890 | UDP | SRT |

L'API de contrôle (9997) n'écoute que la machine elle-même : c'est l'agent de la régie qui la lit (story 47.6).

## Certificat

L'écran régie est une page `https://archilan.fr`. Le navigateur ne la laisse pas lire un flux en `http://`, il faut
donc un vrai certificat pour un nom qui pointe vers l'IP LAN, par exemple `media.lan.archilan.fr` -> `192.168.1.10`.

- **Enregistrement DNS** : un `A` public `media.lan.archilan.fr` vers l'IP privée. Attention, certaines box ou
  certains DNS refusent les réponses en IP privée (protection contre le DNS rebinding). Dans ce cas, mettre
  l'enregistrement dans le DNS local de la LAN, ou utiliser un autre résolveur.
- **Certificat Let's Encrypt par challenge DNS-01**, sans rien exposer sur Internet. Exemple avec lego et le
  fournisseur DNS du domaine (identifiants API du fournisseur en variables d'environnement) :
  ```
  lego --email admin@archilan.fr --dns <fournisseur> --domains media.lan.archilan.fr run
  ```
  Copier ensuite `media.lan.archilan.fr.crt` et `.key` dans `certs/` sous les noms `fullchain.pem` et `privkey.pem`.
  Le certificat dure 90 jours : il faut le renouveler (`lego ... renew`) avant chaque LAN.
- **Ne jamais commiter** `certs/` ni `.env` : le `.gitignore` de ce dossier les exclut.

## Réglages OBS du joueur

**WHIP (recommandé, OBS 30 ou plus récent)** : `Paramètres > Stream`
- Service : `WHIP`
- Serveur : `https://media.lan.archilan.fr:8889/p-<identifiant>/whip`
- Jeton Bearer : la clé, sans « : ». MediaMTX la transmet au site dans le champ `token`.

OBS envoie alors de l'H264 sans B-frames et du son Opus : l'écran régie le lit tel quel.

**SRT (repli)** : `srt://media.lan.archilan.fr:8890?streamid=publish:p-<identifiant>:<pseudo>:<clé>&pkt_size=1316`
- La clé arrive au site dans le champ `password`.
- **B-frames à 0** dans les réglages de l'encodeur. Sinon, MediaMTX refuse la lecture WebRTC (« WebRTC doesn't
  support H264 streams with B-frames »).
- Son en Opus si possible : l'AAC est ignoré à la lecture WebRTC. Ce n'est pas bloquant, l'écran régie est muet.

**Réglages conseillés** : 720p60 ou 1080p60, encodeur GPU (NVENC ou AMF), 6000 kbit/s, une image clé toutes les 1 à
2 secondes.

## Vérification rapide

- Flux en cours : `curl -s http://127.0.0.1:9997/v3/paths/list` sur la machine. Un flux publié a `"online": true`.
- Lecture dans un navigateur de la LAN : `https://media.lan.archilan.fr:8889/p-<identifiant>`, la page de lecture
  intégrée à MediaMTX.
- Lecture depuis le site : `https://archilan.fr/o/whep-essai?url=https://media.lan.archilan.fr:8889/p-<identifiant>/whep`
  (plusieurs `url=` possibles, jusqu'à 9). La page affiche la résolution, les images par seconde, la gigue et les
  paquets perdus de chaque lecteur.

## Essai local (poste de dev, Docker Desktop)

Sans certificat ni réseau de l'hôte, avec l'API lancée en `php -S 0.0.0.0:8000 -t public`, un événement publié qui
commence dans moins de 24 h avec un serveur média renseigné, et un inscrit confirmé (son chemin et sa clé :
`GET /api/v1/registrations/<id>/media-publish`, connecté avec son compte) :

```
docker run -d --name mtx-essai -v "<chemin>/mediamtx.yml:/mediamtx.yml:ro" \
  -e MTX_AUTHHTTPADDRESS=http://host.docker.internal:8000/api/v1/media-auth \
  -e MTX_WEBRTCENCRYPTION=no -e MTX_APIADDRESS=:9997 -e MTX_WEBRTCALLOWORIGINS='*' \
  -p 8889:8889 -p 8890:8890/udp -p 8189:8189/udp -p 9997:9997 bluenviron/mediamtx:1.21.3
```

Publier une mire avec ffmpeg :

```
ffmpeg -re -f lavfi -i testsrc2=size=1280x720:rate=30 -c:v libx264 -preset veryfast -tune zerolatency -bf 0 \
  -g 60 -b:v 2500k -f mpegts "srt://localhost:8890?streamid=publish:<chemin>:archilan:<clé>&pkt_size=1316"
```

Puis ouvrir `http://127.0.0.1:3000/o/whep-essai?url=http://localhost:8889/<chemin>/whep`.

L'agent se lance à côté, sans Docker, avec les lignes de « Configuration de l'agent » :

```
ARCHILAN_API_URL=http://localhost:8000 ARCHILAN_EVENT_ID=<id> ARCHILAN_AGENT_SECRET=<secret> \
  MTX_API_URL=http://localhost:9997 python agent.py
```

**Écran régie en local, en HTTPS** (story 47.8) : l'écran `/o/streams` lit `https://<serveur média>:8889`. Pour
l'essayer sur un poste de dev, `media.localtest.me` pointe vers 127.0.0.1 dans le DNS public :

1. `mkcert -cert-file certs/fullchain.pem -key-file certs/privkey.pem media.localtest.me` ;
2. le `docker run` plus haut sans `-e MTX_WEBRTCENCRYPTION=no`, avec `-v "<chemin>/certs:/certs:ro"` et
   `-e MTX_WEBRTCADDITIONALHOSTS=127.0.0.1` ;
3. « Serveur média local » de l'événement : `media.localtest.me` ; l'agent lancé comme ci-dessus ;
4. ouvrir `http://localhost:3000/o/streams?event=<id>`.
