# Story 41.43: App de blind test, image Docker et déploiement

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-24
**Dépôts :** `ArchiLAN-dev/archilan-blind-test` (image) et `archilan.fr` (compose de prod, documentation)
**Origine :** Jean le 2026-10-24 (« Go la suite »). Suite de la 41.42.

## Story

En tant qu'exploitant,
je veux une image publiée et un service prêt dans le compose de prod,
afin de lancer le blind test le jour de l'event avec une commande, sans le faire tourner le reste du temps.

## Critères d'acceptation

1. **Image** `ghcr.io/archilan-dev/blind-test` : FrankenPHP Alpine épinglé comme `api-web`, `apk upgrade`,
   extensions `pdo_sqlite intl opcache`, dépendances sans dev, cache chauffé. Au démarrage : migrations, puis le
   serveur. Base SQLite sur un volume (`/data`), fichiers audio montés en lecture seule (`/audio`).
2. **SQLite partagé** entre le web et le renvoi des crédits : journal WAL et attente de verrou (5 s), pour qu'une
   écriture simultanée attende au lieu d'échouer.
3. **Proxy** : l'app derrière Traefik reconnaît HTTPS (`TRUSTED_PROXIES`), le cookie de session est sécurisé.
4. **Publication** : workflow GHCR (branche `main` et tags `v*.*.*`), scan Trivy bloquant avant le push, comme
   les autres services.
5. **Compose de prod** (monorepo) : services `blind-test` (web, routé par Traefik sur `BLIND_TEST_DOMAIN`) et
   `blind-test-credits` (`app:credits:renvoyer --boucle`), dans un profil `blind-test` : ils ne démarrent qu'avec
   `--profile blind-test`. L'app appelle l'API par le réseau interne (`http://api-web`).
6. **Documentation** : `envs/blind-test.env.example`, variables de `.env.prod.example`, procédure du jour J dans
   `docs/deploiement-production.md` (charger la playlist, ouvrir la régie, `BLIND_TEST_APP_URL` côté site avec le
   chemin `/rejoindre`).

## Tâches

- [x] App : Dockerfile, entrypoint, `.dockerignore`, `.trivyignore`, workflow de publication, WAL et attente.
- [x] App : proxies de confiance.
- [x] Monorepo : compose de prod, exemples d'env, documentation.
- [x] Vérification : image construite et démarrée en local, page de régie servie.

## Dev Agent Record

- App : PR `archilan-blind-test#2`. Image vérifiée en local (migrations au démarrage, `journal_mode=wal`,
  `busy_timeout=5000`, playlist chargée, régie 200 avec jeton et 403 sans), puis supprimée.
- `.gitattributes` LF dans l'app : une copie Windows en CRLF cassait php-cs-fixer et aurait cassé l'entrypoint.
- Monorepo : `docker compose --profile blind-test config` valide. Version d'image par défaut `latest` tant que l'app
  n'a pas de tag.
