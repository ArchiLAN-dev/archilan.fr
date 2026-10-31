# Story 38.27 - `worlds.__file__` aussi à la génération

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-31
**Origine :** Yu-Gi-Oh! GX - Duel Academy (GBA), mise à jour rejetée (candidat `yugiohgx`, hash `90975e097cfc`),
incident « YAML illisible » ouvert depuis le 2026-10-02 (5 occurrences) : `No world found to handle game Yu-Gi-Oh!
GX: Duel Academy`. Remonté par Jean le 2026-10-31, reproduit ici.
**Dépôts :** `archipelago` (correctif, nouvelle image), `archilan.fr` (cette story).

## Constat

Le YAML n'y est pour rien : le world ne se charge pas à la génération. Plus haut dans le journal :
`Warning: failed to load <hash>.apworld (yugiohgx): module 'worlds' has no attribute '__file__'`.

- `yugiohgx/Client.py` cherche `_bizhawk.apworld` à côté de `sys.modules["worlds"].__file__` (bloc emprunté à
  MM2).
- `generate_template.py` et `introspect_options.py` posent `_worlds_stub.__file__` pour ce world précis (le
  commentaire le nomme). `generate_multiworld.py` ne le pose pas, et `test_worlds_stub_parity.py` l'autorisait
  (« `__file__` is set only where a world resolves paths against it »). Or la génération charge les mêmes worlds :
  template et introspection passent, la génération rejette la mise à jour.

## Décision

`generate_multiworld.py` pose `_worlds_stub.__file__` comme les deux autres scripts, et le test de parité n'admet
plus d'écart sur `__file__` : seul `network_data_package` reste propre à la génération.

## Critères d'acceptation

1. Les trois stubs exposent `__file__` = `<ArchipelagoSrc>/worlds/__init__.py`.
2. Le test de parité échoue si un script l'oublie.
3. Docker, candidat `yugiohgx` avec son template : le world se charge, génération réussie.
4. `pytest` vert.

## Tâches

- [x] `generate_multiworld.py` : `_worlds_stub.__file__`.
- [x] `tests/test_worlds_stub_parity.py` : `__file__` retiré des écarts admis.
- [x] Vérification Docker.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes

- `generate_multiworld.py` pose `_worlds_stub.__file__` ; le test de parité n'admet plus que
  `network_data_package` comme écart. Sans la ligne, `test_no_script_furnishes_the_stub_differently` échoue.
- `pytest` 223 passed (branche partie de `master`, sans 38.24 à 38.26).
- Docker, candidat `yugiohgx` (sha256 `90975e09...`, celui de l'incident) avec son template :
  - `archipelago:latest` : `failed to load ... module 'worlds' has no attribute '__file__'`, puis « No world found » ;
  - image de la branche : le world se charge, génération réussie en 10 s.

### Mise en prod

1. Merger la PR `archipelago`, publier l'image.
2. Yu-Gi-Oh! GX - Duel Academy : relancer le test du candidat (story 38.14) pour appliquer la mise à jour, puis
   résoudre l'incident.

### File List (dépôt archipelago)

- `generate_multiworld.py`
- `tests/test_worlds_stub_parity.py`
