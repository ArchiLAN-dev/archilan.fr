"""Agent de la régie (story 47.6).

Toutes les AGENT_INTERVAL secondes, lit les flux en ligne sur l'API locale de MediaMTX et envoie leur liste au site,
signée avec le secret de l'agent de l'événement (admin du site, bloc « Diffusion locale »). Le site considère un flux
coupé s'il ne le voit plus pendant 30 s : l'agent doit donc tourner tant que dure la LAN.

Depuis la v0.66.1, l'agent envoie aussi la session de chaque flux, et coupe sur MediaMTX les chemins que le site lui
renvoie dans `kick` : un inscrit qui n'a plus le droit de publier (inscription annulée, diffusion refusée, compte
banni, événement terminé), ou une session ouverte avec une clé que le joueur a régénérée depuis.

Bibliothèque standard seule. Variables d'environnement (voir .env.example) :
  ARCHILAN_API_URL       ex. https://archilan.fr
  ARCHILAN_EVENT_ID      l'identifiant de l'événement
  ARCHILAN_AGENT_SECRET  le secret de l'agent de cet événement
  MTX_API_URL            défaut http://127.0.0.1:9997
  AGENT_INTERVAL         défaut 10 (secondes)
"""

import hashlib
import hmac
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

PATH_PATTERN = re.compile(r"^p-[0-9a-f]{20}$")

# Type de source MediaMTX -> collection de l'API qui sait la couper (POST /v3/<collection>/kick/<id>).
KICK_COLLECTIONS = {
    "webRTCSession": "webrtcsessions",
    "srtConn": "srtconns",
    "rtmpConn": "rtmpconns",
    "rtmpsConn": "rtmpsconns",
    "rtspSession": "rtspsessions",
    "rtspsSession": "rtspssessions",
}


def log(message):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), message, flush=True)


def online_streams(mtx_api_url):
    """The p-* paths MediaMTX has online, with when they went online (spike 47.4: `online`, not `ready`) and their
    publishing source, {"type": ..., "id": ...}, kept apart: only its id goes to the site."""
    streams = []
    sources = {}
    page = 0
    while True:
        url = "%s/v3/paths/list?page=%d&itemsPerPage=100" % (mtx_api_url.rstrip("/"), page)
        with urllib.request.urlopen(url, timeout=5) as response:
            data = json.load(response)
        for item in data.get("items") or []:
            name = item.get("name") or ""
            if item.get("online") and PATH_PATTERN.match(name):
                stream = {"path": name}
                if item.get("onlineTime"):
                    stream["onlineSince"] = item["onlineTime"]
                source = item.get("source") or {}
                if source.get("id"):
                    stream["session"] = source["id"]
                    sources[name] = source
                streams.append(stream)
        page += 1
        if page >= int(data.get("pageCount") or 1):
            return streams, sources


def kick(mtx_api_url, path, sources):
    """Cuts the publishing session of a path the site no longer allows. The next report sees it gone."""
    source = sources.get(path) or {}
    collection = KICK_COLLECTIONS.get(source.get("type"))
    if collection is None:
        log("impossible de couper %s : source %s inconnue" % (path, source.get("type")))
        return
    request = urllib.request.Request(
        "%s/v3/%s/kick/%s" % (mtx_api_url.rstrip("/"), collection, source["id"]), data=b"", method="POST"
    )
    try:
        with urllib.request.urlopen(request, timeout=5):
            pass
        log("flux coupé : %s" % path)
    except Exception as error:  # noqa: BLE001 - réessayé au prochain rapport
        log("échec de la coupure de %s : %s" % (path, error))


def report(api_url, event_id, secret, streams):
    body = json.dumps({"streams": streams}, separators=(",", ":")).encode()
    timestamp = str(int(time.time()))
    signature = hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    request = urllib.request.Request(
        "%s/api/v1/events/%s/local-streams" % (api_url.rstrip("/"), event_id),
        data=body,
        method="PUT",
        headers={
            "Content-Type": "application/json",
            "User-Agent": "archilan-lan-media-agent",
            "X-Archilan-Timestamp": timestamp,
            "X-Archilan-Signature": signature,
        },
    )
    with urllib.request.urlopen(request, timeout=10) as response:
        return json.load(response).get("data", {})


def main():
    try:
        api_url = os.environ["ARCHILAN_API_URL"]
        event_id = os.environ["ARCHILAN_EVENT_ID"]
        secret = os.environ["ARCHILAN_AGENT_SECRET"]
    except KeyError as missing:
        log("variable manquante : %s" % missing)
        return 1
    mtx_api_url = os.environ.get("MTX_API_URL", "http://127.0.0.1:9997")
    interval = max(2, int(os.environ.get("AGENT_INTERVAL", "10")))

    log("agent démarré : événement %s, toutes les %d s" % (event_id, interval))
    last = None
    while True:
        try:
            try:
                streams, sources = online_streams(mtx_api_url)
            except Exception as error:  # noqa: BLE001
                raise RuntimeError("MediaMTX injoignable (%s) : %s" % (mtx_api_url, error)) from error
            result = report(api_url, event_id, secret, streams)
            for path in result.get("kick") or []:
                kick(mtx_api_url, path, sources)
            summary = "%s en direct, %s ignorés" % (result.get("live"), result.get("ignored"))
            if summary != last:
                log(summary)
                last = summary
        except urllib.error.HTTPError as error:
            log("le site refuse l'envoi : HTTP %d %s" % (error.code, error.read()[:200].decode(errors="replace")))
            last = None
        except Exception as error:  # noqa: BLE001 - l'agent ne s'arrête jamais sur une erreur passagère
            log("erreur : %s" % error)
            last = None
        time.sleep(interval)


if __name__ == "__main__":
    sys.exit(main())
