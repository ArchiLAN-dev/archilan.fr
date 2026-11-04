# Story 50.6 - Bridge sans Docker, serveur non root, limites des conteneurs

Status: review

## Contexte

Suite du durcissement de l'audit de sécurité du 2026-11-03. Code dans les dépôts `archipelago`,
`bridge` et `orchestrateur`, sur la branche `feature/epic-50-story-6-socket-et-non-root`.

## Acceptance Criteria

1. Un bridge ne monte que son volume de session et n'a aucun accès à Docker.
2. Le suivi d'une run reste disponible, y compris après le redémarrage du serveur sous un bridge actif.
3. Le bridge lit une sauvegarde réelle sans les sources d'Archipelago, avec une liste fermée de types.
4. Le serveur et son daemon de suivi tournent en uid 1000, sans capacité effective.
5. Les conteneurs de génération, d'introspection et de test perdent leurs capacités inutiles et ont une
   limite de processus.
6. `SESSION_MEMORY_LIMIT_MB` borne la mémoire de ces conteneurs ; 0 la laisse libre.
7. Une run se lance alors que l'orchestrateur n'est sur aucun réseau de session.

## Tasks

- [x] archipelago : `reachable_host.py`, passage en uid 1000 dans `ap_server.sh`
- [x] bridge : `ReachableSocketAdapter`, types de la sauvegarde, retrait de `aiodocker`
- [x] orchestrateur : montages du bridge, limites, `WaitForPort`
- [x] doc de déploiement, exemple d'env

## Notes

- AC 7 corrige un défaut de la story 50.2, jamais publié : la disponibilité du serveur se vérifiait
  par une connexion directe depuis l'orchestrateur.
- Validé avec le vrai orchestrateur et les vraies images, sur trois réseaux internes, avec une API de
  substitution : lancement, suivi sur tous les slots, rappels vers l'API, arrêt, relance depuis une
  sauvegarde, redémarrage du serveur. Suivi comparé à l'ancien chemin sur trois sessions (6 slots) et
  lecture des sauvegardes comparée à `read_save.py` sur 19 sauvegardes réelles : résultats identiques.
- Une génération réelle passe sous les limites, avec 2 Go.
- Non validé : derrière le vrai Traefik, sur une grosse run, avec une valeur de mémoire de production.
- `read_save.py` reste dans l'image : plus rien ne l'appelle une fois les anciennes sessions arrêtées.
- Le code des worlds tourne encore en root dans les conteneurs jetables, sans réseau ni capacité
  système : l'uid 1000 n'y est pas appliqué, ils écrivent dans un volume rempli par root.
