# Story 38.26 - Une bibliothèque embarquée dans l'apworld n'est plus remplacée par un bouchon

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-31
**Origine :** Super Junkoid (`superjunkoid`, hash `7e747eac28ba`) a un incident « Erreur de génération » ouvert
depuis le 2026-09-27 (4 occurrences) : `AssertionError: can't call generate_output without create_regions`.
Remonté par Jean le 2026-10-31, reproduit ici.
**Dépôts :** `archipelago` (correctif, nouvelle image), `archilan.fr` (cette story).

## Constat

Le message est trompeur : `create_regions` a bien tourné. Le journal le dit plus haut :
`Note: stubbed missing module 'super_junkoid_randomizer'`.

- L'apworld embarque sa bibliothèque dans `superjunkoid/super_junkoid_randomizer/` mais l'importe en **premier
  niveau** (`from super_junkoid_randomizer.game import Game`).
- Dans Archipelago, l'apworld est chargé depuis le zip : `__file__` contient « .apworld », et `config.py` extrait la
  bibliothèque dans `lib/` puis ajoute `lib` au `sys.path` (même mécanique que Super Metroid).
- Nos scripts extraient l'apworld dans `/tmp/apworld_xxx/` : `__file__` ne contient plus « .apworld », la mise en
  place n'a jamais lieu, l'import échoue, et `import_world` remplace la bibliothèque par un bouchon. Le jeu tourne
  alors sur du vide : `make_sj_game` rend un `Stub`, faux en booléen, d'où l'`assert`. Selon l'image, l'échec sort
  ailleurs (`KeyError: 'Super Junkoid'` en local : le data package du jeu, plein de `Stub`, n'est pas sérialisable).

## Décision

Dans `import_world` (partagé par les quatre scripts), avant de bouchonner un module manquant : si le world qu'on
importe embarque un **sous-paquet** de ce nom (`<paquet du world>/<nom>/__init__.py`), il devient importable
sous ce nom de premier niveau, et l'import est retenté honnêtement. C'est ce que fait le `lib/` d'Archipelago.

Seul ce nom est exposé, par un finder dédié : le dossier du world n'est pas ajouté au `sys.path`, ses autres
modules (`item.py`, `options.py`...) ne deviennent pas importables en premier niveau et ne masquent rien pour les
autres worlds.

## Critères d'acceptation

1. Un world qui importe en premier niveau un sous-paquet qu'il embarque le charge pour de vrai, sans bouchon.
2. Un module vraiment absent est toujours bouchonné ; un module embarqué n'est exposé que s'il manquait.
3. Les autres modules du dossier du world ne deviennent pas importables en premier niveau.
4. Docker, Super Junkoid aux options par défaut : plus de note « stubbed missing module », génération réussie.
5. Tests ; `pytest` vert.

## Tâches

- [x] `apworld_import.py` : finder des bibliothèques embarquées, appelé par `import_world`.
- [x] Tests dans `tests/test_apworld_import.py`.
- [x] Vérification Docker.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes

- `apworld_import.py` : `BundledLibraryFinder` (en fin de `sys.meta_path`, ne répond qu'aux noms enregistrés) et
  `_expose_bundled_library()`. Sur un `ImportError` d'un module de premier niveau, avant tout bouchon, `import_world`
  regarde si le world importé (`worlds.<paquet>`) a `<dossier du world>/<nom>/__init__.py` ; si oui, le nom est
  enregistré et l'import retenté honnêtement. Les quatre scripts en profitent, sans modification.
- Tests : 2 nouveaux dans `tests/test_apworld_import.py` (le harnais rend aussi la liste des bouchons) ; le premier
  échoue sans le correctif. `pytest` 225 passed (branche partie de `master`, sans 38.24 ni 38.25).
- Docker, `superjunkoid` (sha256 `7e747eac...`, celui de l'incident), options par défaut :
  - `archipelago:latest` : bouchon `super_junkoid_randomizer`, échec (`KeyError: 'Super Junkoid'` en local) ;
  - image de la branche : plus de bouchon pour ce jeu, génération réussie en 11 s (patch `.apsj`, spoiler).
- Introspection, même apworld : 0 lieu avec l'image actuelle (la liste vient de la bibliothèque bouchonnée),
  70 lieux et 21 objets avec la branche. Les lieux du jeu en base sont donc vides aujourd'hui.

### Mise en prod

1. Merger la PR `archipelago`, publier l'image.
2. Super Junkoid : `php bin/console app:games:backfill-option-types --reintrospect --game <slug>` puis
   `php bin/console app:games:backfill-locations` (lieux vides jusqu'ici), « Régénérer » le template.
3. Fiche admin : relancer le test de l'apworld, puis résoudre l'incident.

### File List (dépôt archipelago)

- `apworld_import.py`
- `tests/test_apworld_import.py`
