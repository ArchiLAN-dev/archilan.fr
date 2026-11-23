# Déploiement continu du site

Story 33.34. Le serveur déploie de lui-même chaque nouvelle release du site, sauf quand c'est risqué, et prévient
sur Discord. Le script est **tiré par la machine** : il lit les releases GitHub, publiques, et GitHub n'a aucun accès
au serveur.

## Ce qu'il fait

À chaque passage (toutes les 15 minutes de 9 h à 22 h), `deploy/archilan-deploy.sh` :

1. lit la dernière release (`/repos/ArchiLAN-dev/archilan.fr/releases/latest`) et la compare à `ARCHILAN_VERSION`
   du `.env`. Déjà en place : il s'arrête sans rien écrire ;
2. **refuse** et prévient une fois :
   - si la section de la release contient une ligne qui commence par `**Déploiement manuel**`
     ([`processus-de-release.md`](processus-de-release.md)) ;
   - si la version change de majeure ;
3. **attend** et prévient une fois, de 2 jours avant à 3 jours après le **début** d'un event (`startsAt` de
   `/api/v1/events`). Il déploie seul à la fin de ce gel. Une ArchiWAN qui dure un mois ne gèle que son début ;
4. tire les images de la nouvelle version. Tant qu'elles ne sont pas publiées (le workflow de release les construit
   après le tag), il réessaie au passage suivant, sans prévenir ;
5. sauvegarde la base (`backups/archilan-<version d'avant>-<date>.sql.gz`, gardée 14 jours) ;
6. écrit la nouvelle `ARCHILAN_VERSION`, joue les migrations (`api-migrations`, qui doit sortir en 0), puis relance
   `api-web`, `api-worker` et `frontend` ;
7. **vérifie** pendant 5 minutes au plus : `https://<API_DOMAIN>/api/v1/version` et la balise
   `<meta name="archilan-version">` de `https://<FRONTEND_DOMAIN>/` donnent la nouvelle version, avec un 200 ;
8. **en cas d'échec**, remet l'ancienne version et relance les trois services. La base n'est pas restaurée seule :
   si une migration est passée, voir « Restaurer la base ».

Il ne touche jamais à l'orchestrateur, au bridge ni à l'image Archipelago, qui restent déployés à la main
([`deploiement-production.md`](deploiement-production.md)).

Une version refusée ou en échec n'est plus retentée, et ne déclenche plus d'alerte, jusqu'à la suivante. Le dernier
état est dans `.deploy-state`, à côté du `.env`.

## Alertes

Le bot du site (`DISCORD_BOT_TOKEN` de `envs/api.env`) poste dans le salon `DEPLOY_CHANNEL_ID` :

- un message « Déploiement de vX.Y.Z en cours… », **modifié** ensuite en « déployée et vérifiée » ou en « échec »
  (avec la raison, la version remise en place et le chemin de la sauvegarde) ;
- un message seul pour un refus ou un gel ;
- une mention de `DEPLOY_ALERT_ROLE_ID` (facultatif) seulement pour un échec ou un refus.

Rien n'est posté quand il n'y a rien à faire. Un envoi Discord raté n'arrête pas le déploiement : il est écrit dans
le journal. Le bot doit pouvoir **voir le salon, y envoyer des messages** et, si le rôle est posé, le mentionner.

## Installation

Une fois, sur la machine, dans le dossier du compose (celui de `docker-compose.yaml` et du `.env`, `/opt/archilan`
dans les exemples) :

1. Outils : `bash`, `curl`, `jq`, `flock` et `gzip` (`apt install jq util-linux gzip curl`), et `docker compose` v2.
2. Le script, à côté du compose :

   ```bash
   cp deploy/archilan-deploy.sh /opt/archilan/archilan-deploy.sh
   chmod 750 /opt/archilan/archilan-deploy.sh
   ```

   Il doit pouvoir lire `envs/api.env` et `envs/postgres.env`, écrire le `.env`, `.deploy-state`, `.deploy.lock` et
   `backups/`, et piloter Docker : root, ou un utilisateur du groupe `docker` propriétaire du dossier.
3. Le `.env` (voir `.env.prod.example`) : `DEPLOY_CHANNEL_ID`, `DEPLOY_ALERT_ROLE_ID` (facultatif),
   `DEPLOY_PAUSED=0`. `API_DOMAIN`, `FRONTEND_DOMAIN` et `ARCHILAN_VERSION` y sont déjà.
4. Un essai à blanc, qui ne change rien et ne poste rien :

   ```bash
   /opt/archilan/archilan-deploy.sh --dir /opt/archilan --dry-run
   ```

   Il dit s'il est à jour, ce qu'il refuserait, ou « Déploierait vX.Y.Z ».
5. Le timer (adapter le chemin dans le `.service` s'il n'est pas `/opt/archilan`) :

   ```bash
   cp deploy/archilan-deploy.service deploy/archilan-deploy.timer /etc/systemd/system/
   systemctl daemon-reload
   systemctl enable --now archilan-deploy.timer
   systemctl list-timers archilan-deploy.timer
   ```

Le premier vrai passage se fait accompagné (critère 7 de la story) : `systemctl start archilan-deploy.service` puis
`journalctl -u archilan-deploy -f`.

## Au quotidien

- **Couper** le déploiement automatique : `DEPLOY_PAUSED=1` dans le `.env`. Le site reste à `ARCHILAN_VERSION` ;
  un déploiement à la main se fait comme avant.
- **Journal** : `journalctl -u archilan-deploy` (un passage sans rien à faire écrit une ligne « À jour »).
- **Retenter** une version refusée ou en échec, une fois la cause réglée : supprimer `.deploy-state` (ou
  déployer à la main, ce qui met `ARCHILAN_VERSION` à jour et rend le script muet sur cette version).
- **Déploiement manuel** (release qui porte le marqueur) : suivre sa section « Déploiement » du CHANGELOG, puis mettre
  `ARCHILAN_VERSION` à la nouvelle version ; le script la voit en place et ne fait plus rien.

## Restaurer la base

Seulement si une migration de la version en échec est passée et que l'ancienne version ne fonctionne pas avec :

```bash
cd /opt/archilan
docker compose stop api-web api-worker
gunzip -c backups/archilan-<version>-<date>.sql.gz | docker compose exec -T postgres psql -U archilan -d archilan
docker compose up -d api-web api-worker
```

La sauvegarde est faite avec `--clean --if-exists` : elle remplace les tables en place. Les écritures faites entre
la sauvegarde et la restauration sont perdues.
