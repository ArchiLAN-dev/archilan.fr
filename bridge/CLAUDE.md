# bridge/ - Agent Standards

## Launch requirement

The bridge **must be started from the repo root** (the parent of `bridge/`):

```bash
# Correct - run from repo root
python bridge/bridge.py

# Correct - module mode from repo root
python -m bridge.bridge
```

Running from any other working directory is **not supported**. The bridge uses absolute package imports (`from bridge.core.X import Y`) that rely on the repo root being on `sys.path`. Python adds CWD to `sys.path` automatically, so launching from the repo root is sufficient - no `PYTHONPATH` manipulation is needed.

The old `sys.path.insert` hack (removed in Story 20.3) previously allowed launching from any directory. That is no longer the case.

## Quality gates

From the repo root:

```bash
cd bridge
python -m ruff check .
python -m pytest

# Mypy must be run from the repo root (not from bridge/)
cd ..
python -m mypy bridge/ --config-file bridge/pyproject.toml
```
