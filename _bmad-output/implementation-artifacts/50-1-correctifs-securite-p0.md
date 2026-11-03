# Story 50.1 - Correctifs de sécurité P0 (import de seed, webhook HelloAsso)

Status: review

## Contexte

Durcissement issu de l'audit de sécurité du 2026-11-03.

## Acceptance Criteria

1. Aucun script de l'image archipelago ni le bridge ne désérialise avec `pickle` sans liste fermée.
2. Le conteneur éphémère de `read_save.py` tourne sans réseau.
3. L'API n'accepte à l'import que `.zip` et `.archipelago`.
4. L'orchestrateur écarte tout `.apsave` d'une archive et refuse un `.apsave` comme fichier de lancement.
5. Le message de paiement HelloAsso porte uniquement les données de la commande relue chez HelloAsso.
6. Une commande sans article `Processed` n'est pas déclarée payée par le webhook ; la synchro du
   formulaire reste déclenchée.

## Tasks

- [x] archipelago : `restricted_loads` dans `read_save.py` et `reachable.py`, test `test_no_raw_pickle.py`
- [x] bridge : `_SaveUnpickler`, `NetworkMode: none` sur `run_save_parse`, tests d'exploit
- [x] orchestrateur : filtre `.apsave` dans `zipToOutputTar` et au lancement, test
- [x] api : extensions à l'import, `HandleHelloAssoWebhook` sur la commande relue, tests

## Notes

- Les quatre dépôts sont indépendants : chacun bloque la chaîne seul, mais les déployer ensemble.
- À valider dans la vraie image : `restricted_loads` sur une vraie sauvegarde.
- À valider sur une vraie commande : présence de `formSlug`, `formType` et `items[].state` sur
  `GET /orders/{id}`. S'ils manquent, l'adhésion passe quand même par la synchro du formulaire.
