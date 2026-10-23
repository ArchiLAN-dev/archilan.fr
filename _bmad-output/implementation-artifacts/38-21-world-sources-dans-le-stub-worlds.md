# Story 38.21 - `worlds.world_sources` dans le stub `worlds`

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-23
**Origine :** Pokémon Noir et Blanc (`pokemon_bw` 0.4.2) n'a pas de template : la génération échoue sur
`ImportError: cannot import name 'world_sources' from 'worlds'`, puis `No game registered from the apworld(s)`.
Diagnostic remonté par Jean le 2026-10-23, reproduit ici.
**Dépôts :** `archipelago` (correctif, nouvelle image, ArchiLAN-dev/archilan-archipelago#42), `archilan.fr` (cette story).

## Constat

`pokemon_bw/options/sanity.py` détecte son extension optionnelle à l'import :

```python
from worlds import world_sources
DEXSANITYSANITY_ENABLED = any(Path(source.path).stem == "pokemon_bw_dexsanitysanity" for source in world_sources)
```

Archipelago expose `world_sources` dans `worlds/__init__.py`. Nos trois scripts qui chargent un apworld
(`generate_template.py`, `introspect_options.py`, `generate_multiworld.py`) remplacent `worlds` par un stub qui expose
`AutoWorldRegister`, `World`, `local_folder`, `user_folder` et `failed_world_loads`, pas `world_sources`. Le défaut ne
dépend pas de la version d'Archipelago : il se reproduit avec l'image actuelle (0.6.8).

`reachable.py` importe le vrai paquet `worlds` et n'est pas concerné.

## Critères d'acceptation

1. Les trois stubs exposent `world_sources`, une liste d'objets ayant `path`, `is_zip`, `relative`, `name` et
   `resolved_path` comme `worlds.WorldSource` d'Archipelago 0.6.8.
2. La liste contient tous les apworlds du dossier chargé, remplie **avant** le premier import : l'extension
   `pokemon_bw_dexsanitysanity` se trie après `pokemon_bw`.
3. `pokemon_bw` s'importe et génère son template ; avec son extension dans le dossier, il la détecte.
4. Un test de parité garantit que les trois stubs fournissent l'attribut.
5. Vérification Docker avec le vrai `pokemon_bw.apworld` : template, introspection et génération.

## Tâches

- [x] `generation_setup.py` : `WorldSource` et `apworld_sources(directory)`, partagés.
- [x] Les trois scripts : `_worlds_stub.world_sources = []`, rempli avant la boucle d'import.
- [x] Tests : `tests/test_world_sources.py`, `tests/test_worlds_stub_parity.py`.
- [x] Vérification Docker.

## Dev Agent Record

### Completion Notes

- Correctif dans `generation_setup.py` (un helper pour les trois scripts) plutôt qu'un apworld modifié : un apworld
  patché divergerait de celui que les joueurs installent, et le trou toucherait tout autre world lisant
  `world_sources`.
- Tests rouges sans le correctif (6 échecs), `pytest` : 195 passed.
- Docker, `pokemon_bw` 0.4.2 (sha256 `fde300c8...`) :
  - image de la branche : template généré, introspection OK (3306 lieux, 621 objets, 10 groupes), génération
    complète OK ;
  - image actuelle : l'`ImportError` est reproduite.
- ROM : le world déclare trois fichiers (`PokemonBWSettings.black_rom`, `white_rom`, `dynamic_rom`, des `.nds`
  de 256 Mo, au-dessus de la limite de 64 Mo de l'epic 44), mais la génération n'en lit aucun : elle produit un patch
  `.apbw` de 14 Ko que le joueur applique chez lui. Aucun envoi de ROM pour ce jeu.

### Mise en prod

1. Merger archilan-archipelago#42, publier l'image.
2. Pokémon Black and White : « Régénérer » le template dans la fiche admin du jeu.
3. `php bin/console app:games:backfill-option-types --reintrospect --game <slug>` puis
   `php bin/console app:games:backfill-locations`.

### File List (dépôt archipelago)

- `generation_setup.py`
- `generate_template.py`
- `introspect_options.py`
- `generate_multiworld.py`
- `tests/test_world_sources.py` (nouveau)
- `tests/test_worlds_stub_parity.py`
