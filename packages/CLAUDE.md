# packages/ - Règles impératives pour les agents

## Ce que contient ce répertoire

Des librairies PHP standalone, versionnées indépendamment du projet principal :

| Package | Rôle |
|---------|------|
| `bridge-client/` | Client PHP typé pour le bridge REST + WebSocket |
| `bridge-client-bundle/` | Bundle Symfony qui câble le bridge-client dans le conteneur DI |
| `bridge-client-bundle-test/` | Squelette Symfony de validation du câblage DI du bundle |
| `orchestrateur-client/` | Client PHP typé pour l'API REST de l'orchestrateur |
| `orchestrateur-client-test/` | Sandbox de test du client orchestrateur |

---

## Règle absolue : ne jamais modifier un package pendant l'intégration

Quand tu travailles sur `api/`, `frontend/`, ou tout autre composant qui **consomme** ces packages :

1. **Traite chaque package comme une boîte noire** - son API publique est figée.
2. **Si l'intégration révèle un manque** dans un package (méthode absente, type manquant, comportement insuffisant) :
   - **STOP.** N'ajoute rien au package directement.
   - Décris le gap au user : "Il manque X dans `archilan/bridge-client` pour pouvoir faire Y."
   - Attends la confirmation que la demande est valide.
   - Une story dédiée est créée, puis un PR séparé modifie le package.
3. **Le code consommateur s'adapte à l'API du package**, jamais l'inverse.
4. **Ne contourne jamais** un package en réécrivant sa logique dans `api/` (ex : réécrire un appel HTTP brut parce que le client ne l'expose pas encore).

---

## Pourquoi cette règle existe

Ces packages ont leurs propres quality gates (PHPStan level 9, tests unitaires), leur propre historique git, et sont potentiellement réutilisés par plusieurs consommateurs. Une modification ad hoc pendant l'intégration :
- Casse les quality gates du package sans que le consumer s'en rende compte
- Introduit du couplage implicite entre la feature en cours et la librairie
- Rend impossible de raisonner sur les versions et la compatibilité

---

## Cycle de modification d'un package (si vraiment nécessaire)

```
1. Identifier le gap précisément (quelle méthode, quel comportement)
2. En discuter avec le user - est-ce un vrai besoin ou un design smell côté consumer ?
3. Story dédiée : "feat(bridge-client): ajouter X"
4. Branche feature/ dans le monorepo → modifier le package → quality gates verts
5. PR du package → mergé → version bumped si applicable
6. Revenir à l'intégration dans api/ en utilisant la nouvelle API
```

---

## Quality gates par package

| Package | PHPStan | CS-Fixer | Tests |
|---------|---------|----------|-------|
| `bridge-client/` | level 9 | - | PHPUnit ^11 |
| `bridge-client-bundle/` | level 9 | - | - |
| `orchestrateur-client/` | level 9 | - | PHPUnit ^11 |

Avant tout commit sur un package, les gates de CE package doivent être verts.
Les gates de `api/` (`vendor/bin/phpstan analyse src tests`, `php-cs-fixer`, `phpunit`) ne s'appliquent pas aux packages.
