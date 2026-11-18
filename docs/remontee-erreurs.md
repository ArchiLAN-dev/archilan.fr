# Remontée des erreurs (Bugsink)

Story 33.32. Les erreurs de l'API, du worker, du front et des bridges remontent dans **Bugsink**, auto-hébergé, qui
parle le protocole Sentry. Les erreurs identiques sont regroupées en un problème avec un compteur, et un mail part au
premier événement d'un nouveau problème.

Tant qu'un DSN n'est pas posé, rien n'est envoyé : le code est livré éteint.

## Ce qui remonte

| Source | Comment | DSN |
|---|---|---|
| API et worker | Monolog en prod : tout log `error` ou plus, avec sa trace s'il porte une exception (les exceptions attrapées et journalisées aussi, comme un push du fil en échec) | `SENTRY_DSN` dans `envs/api.env` |
| Serveur Next.js | `instrumentation.ts`, erreurs de rendu et de requête | `SENTRY_DSN` dans `envs/frontend.env` (runtime) |
| Navigateur | `instrumentation-client.ts` | variable GitHub Actions `NEXT_PUBLIC_SENTRY_DSN` (au build de l'image) |
| Bridges | SDK Python, identifiant de session en tag | `BRIDGE_SENTRY_DSN` dans `envs/orchestrateur.env` |

Ne remonte pas : les erreurs client (4xx), les coupures réseau et extensions côté navigateur, les traces de
performance, le replay de session.

Données personnelles : `send_default_pii` est coupé partout ; une requête ne garde que sa méthode et son chemin (ni
en-têtes, ni cookies, ni corps, ni query string) ; les adresses e-mail sont masquées en `[email]` dans les messages,
les exceptions et le contexte des logs.

## Mise en service

1. **Base et rôle dans le Postgres d'ArchiLAN** (une seule fois) :

   ```bash
   docker exec -it archilan-postgres psql -U archilan -d archilan
   ```
   ```sql
   CREATE ROLE bugsink LOGIN PASSWORD '...';   -- openssl rand -hex 24
   CREATE DATABASE bugsink OWNER bugsink;
   ```

2. **`envs/bugsink.env`** à partir de `envs/bugsink.env.example` : `SECRET_KEY`, `DATABASE_URL` (le mot de passe du
   rôle), `CREATE_SUPERUSER` pour le premier compte, le SMTP des alertes. `BUGSINK_DOMAIN` dans le `.env` de
   production, et l'entrée DNS qui va avec.

3. **Démarrer** : `docker compose up -d bugsink`. Se connecter sur `https://BUGSINK_DOMAIN`, puis retirer
   `CREATE_SUPERUSER` du fichier.

4. **Créer une équipe et quatre projets** : `api`, `front`, `bridge` (le worker partage le projet `api`). Chaque
   projet donne son DSN.

5. **Poser les DSN** :
   - `envs/api.env` : `SENTRY_DSN=` le DSN du projet `api` (adresse publique, `https://...`) ;
   - `envs/frontend.env` : `SENTRY_DSN=` le DSN du projet `front` ;
   - GitHub, Settings, Variables, Actions : `NEXT_PUBLIC_SENTRY_DSN` = le même DSN `front`. Il est inclus au build :
     il faut une nouvelle image du front pour qu'il serve. Il est public par nature (lu par le navigateur) ;
   - `envs/orchestrateur.env` : `BRIDGE_SENTRY_DSN=` le DSN du projet `bridge`, **avec l'hôte interne** :
     remplacer `https://BUGSINK_DOMAIN` par `http://bugsink:8000`. Les bridges sont sur `archilan-callbacks`, sans
     accès à Internet ; Bugsink y est aussi.

6. **Redémarrer** `api-web`, `api-worker`, `frontend` et l'orchestrateur. Les bridges déjà lancés gardent leur
   environnement : seuls les bridges lancés ensuite remontent.

7. **Alertes** : dans chaque projet, activer l'envoi d'un mail pour un nouveau problème.

## Garde-fous

- Conteneur limité à 768 Mo et un CPU (environ 315 Mo au repos, mesuré le 2026-11-19).
- Quotas d'ingestion par projet et rétention bornée (`MAX_EVENTS_PER_PROJECT_*`,
  `MAX_RETENTION_PER_PROJECT_EVENT_COUNT`, `MAX_EVENT_AGE_DAYS`) : une erreur en boucle ne remplit ni la base ni le
  disque.
- `PHONEHOME=False` : pas de statistiques d'usage envoyées à l'éditeur.
- Inscription fermée (`USER_REGISTRATION=CB_ADMINS`) : un compte s'obtient par invitation d'un admin.

## Vérifier

Une exception provoquée par une commande en prod doit apparaître en moins d'une minute :

```bash
docker exec archilan-api-web php bin/console dbal:run-sql "SELECT 1 FROM table_inexistante"
```

Vérifié en local le 2026-11-19 contre `bugsink/bugsink:2.6.3` : un seul événement par exception (pas de doublon
entre l'écouteur du bundle et Monolog), version et environnement présents, e-mail masqué ; une 404 ne crée rien ;
une erreur du bridge arrive avec son `session_id`.
