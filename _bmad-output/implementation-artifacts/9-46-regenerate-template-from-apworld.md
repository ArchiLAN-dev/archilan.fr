# Story 9.46: Regenerate the template from the stored apworld

Status: review
Related: 9.45 (editable default template), 9.38 (upload preflight), 9.44 (honest world imports)

## Story

As an **admin**,
I want **to regenerate a game's YAML template from the apworld already stored on the
server**,
so that **I can undo a bad edit, and repair games whose template failed at upload, without
hunting down the original file**.

## Context - two distinct needs, one mechanism

1. **Undo an edit (9.45).** Once an admin can edit the template, they need a way back to the
   generated one. Restoring the stored copy is not enough: 9.45 overwrites it on save, so
   the original is gone. The only truthful source is the apworld binary itself.
2. **Repair stale failures.** The template is produced **once, at upload**. Games whose
   generation failed then keep an empty template forever - nothing recomputes it. Story 9.44
   fixed the loader so several worlds now import correctly (Castlevania - Aria of Sorrow
   among them), but their stored template stays empty until the apworld is re-uploaded by
   hand. For an 8 MB file, or when the admin no longer has it, that is a real dead end.

Both are answered by the same capability: re-run `generate_template.py` against the apworld
already in object storage.

## Acceptance Criteria

**AC1 - Regenerate on demand:** an orchestrator endpoint re-runs template generation from
the stored apworld and replaces `{hash}.yaml`. It reuses the existing one-shot container
path (same image, no network) - no new generation machinery.

**AC2 - The game follows:** the admin action updates the game's `default_yaml` with the
regenerated template, and refreshes the introspected option types and location names in the
same pass, so every derived value is consistent with the template shown.

**AC3 - Honest failure:** when generation still fails (a world that genuinely cannot load),
the stored template and the game are left untouched and the admin gets the actionable error
excerpt, parsed exactly like a generation failure (story 9.40). A world that cannot produce
a template must not silently blank an existing one.

**AC4 - Placement:** the action sits next to the template editor as "Réinitialiser depuis
l'apworld", with a confirmation, since it discards the admin's edits.

**AC5 - Quality gates:** orchestrateur `go test ./...`, api `composer gates`, frontend
`pnpm gates`.

## Tasks / Subtasks

- [x] Task 1: orchestrateur - `POST /apworlds/{hash}/template` regenerating from the stored
      apworld (AC1, AC3) + Go test.
- [x] Task 2: package `orchestrateur-client` - `regenerateYamlTemplate(hash)`.
- [x] Task 3: api - command refreshing `default_yaml`, option types and location names
      (AC2), with the failure path leaving state untouched (AC3); admin endpoint; unit
      tests.
- [x] Task 4: frontend - "Réinitialiser depuis l'apworld" with confirmation next to the
      editor (AC4).
- [x] Task 5: gates (AC5).

## Dev Notes

- This is the missing repair path flagged while diagnosing 9.44: after any loader fix, the
  pool needs a way to recompute templates without re-uploading files.
- Reuses `GenerateTemplate` and the introspection call already used at upload
  (`AdminGameLibrary::configureApworld`) - the difference is only where the bytes come from
  (object storage instead of the HTTP request).
- Ordering with 9.45: the regenerated template must also be re-tested, so it goes through
  the same post-save path (preflight re-run).
