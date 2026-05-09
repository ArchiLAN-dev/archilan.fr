# Story 9.5 - Multiworld Generation Pipeline

Status: done

## Review Findings

- The runner marked a generation as `generated` whenever `ArchipelagoGenerate` exited with code `0`, even if no `.archipelago` file was produced.
- That violated the acceptance criterion requiring the generated output to be stored under `/workspace/{sessionId}/output/` and could leave the admin with a generated session that the server lifecycle could not launch.
- Existing tests explicitly allowed a successful status with `outputFile = null`, so the regression was locked in.

## Corrections

- Generation now treats a zero exit without any `.archipelago` output as a failed generation.
- The failure stores an explicit error message on the session and notifies the API with `failed`.
- The success path still stores the first produced `.archipelago` file under the session output directory.
- Tests now assert the exact subprocess invocation against the YAML directory and output directory.
- The previous "success without output" test now verifies the corrected failure behavior.

## Validation

- `python -m pytest runner/tests/test_generation.py`
- `python -m pytest runner/tests/test_generation.py runner/tests/test_server_lifecycle.py`
- `python -m pytest` from `runner`
