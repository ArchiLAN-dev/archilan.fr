# Serveur média de la LAN

Le serveur média permet aux joueurs d'envoyer leur écran à la régie par le réseau de la LAN, sans rien publier sur
Internet (epic 47, partie 2). Ce dossier a été créé par le spike 47.4. La configuration fonctionne, mais la clé est
encore une clé d'essai unique : c'est la story 47.5 qui donnera une clé par inscription.

## Fonctionnement

- **MediaMTX** tourne sur une machine de la LAN, en général la régie. Il reçoit les flux des joueurs :
  - en WHIP, depuis OBS 30 ou plus récent ;
  - en SRT, en repli.
- **Avant chaque publication**, MediaMTX demande l'autorisation au site (`POST /api/v1/media-auth`). Le site répond
  200 ou 401. Seule cette question passe par Internet.
- **La régie lit les flux en WHEP (WebRTC)**, uniquement sur la LAN. Cette lecture ne demande rien au site : si
  Internet tombe, les flux déjà ouverts restent lisibles.
- **Les chemins** ont la forme `p-<identifiant>`. Tout autre chemin est refusé par MediaMTX lui-même.

## Installation sur la machine de régie (Linux)

1. Installer Docker et le plugin compose.
2. Copier ce dossier, puis `cp .env.example .env` et renseigner :
   - `MTX_AUTHHTTPADDRESS` : `https://archilan.fr/api/v1/media-auth` ;
   - `MTX_WEBRTCADDITIONALHOSTS` : l'IP LAN fixe de la machine, par exemple `192.168.1.10`.
3. Mettre le certificat dans `certs/` (voir plus bas) : `fullchain.pem` et `privkey.pem`.
4. `docker compose up -d`, puis `docker compose logs -f`.
5. Côté site, pendant le spike, `MEDIA_AUTH_SPIKE_KEY` doit avoir une valeur dans l'environnement de l'API. Sans
   elle, l'endpoint répond 404 et toute publication est refusée.

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

Sans certificat ni réseau de l'hôte, avec l'API lancée en `php -S 0.0.0.0:8000 -t public` et
`MEDIA_AUTH_SPIKE_KEY` dans `api/.env.local` :

```
docker run -d --name mtx-spike -v "<chemin>/mediamtx.yml:/mediamtx.yml:ro" \
  -e MTX_AUTHHTTPADDRESS=http://host.docker.internal:8000/api/v1/media-auth \
  -e MTX_WEBRTCENCRYPTION=no -e MTX_APIADDRESS=:9997 -e MTX_WEBRTCALLOWORIGINS='*' \
  -p 8889:8889 -p 8890:8890/udp -p 8189:8189/udp -p 9997:9997 bluenviron/mediamtx:1.21.3
```

Publier une mire avec ffmpeg :

```
ffmpeg -re -f lavfi -i testsrc2=size=1280x720:rate=30 -c:v libx264 -preset veryfast -tune zerolatency -bf 0 \
  -g 60 -b:v 2500k -f mpegts "srt://localhost:8890?streamid=publish:p-alice:alice:<clé>&pkt_size=1316"
```

Puis ouvrir `http://127.0.0.1:3000/o/whep-essai?url=http://localhost:8889/p-alice/whep`.
