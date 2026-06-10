# Story 4.13: Item Links Advanced Configuration in YAML Editor

Status: done

## Story

As a registrant,
I want to configure `item_links` rules in the YAML editor in Advanced mode,
So that I can set up item-sharing groups with other players in the Archipelago multiworld.

## Acceptance Criteria

1. **Given** a slot YAML that contains an `item_links` key **When** the user is in Simple mode **Then** the Item Links field shows a banner "Configurez les item links en mode Avancé" instead of any editor controls.

2. **Given** a slot YAML that contains an `item_links` key **When** the user switches to Advanced mode **Then** the Item Links section renders as a list of item link cards, each with all configuration fields.

3. **Given** the user clicks "+ Ajouter un lien" in the Item Links section **When** a new entry card appears **Then** it contains: name field (text), item_pool list (strings + "Everything" shortcut), replacement_item field (text or null toggle), link_replacement Yes/No toggle, local_items list, non_local_items list.

4. **Given** the user configures an item link entry and saves **When** the YAML is serialized **Then** `item_links` contains a correctly structured array of objects matching the Archipelago spec (name as string, item_pool as list, replacement_item as string or null, link_replacement as boolean, local_items/non_local_items as lists).

5. **Given** a previously saved YAML with `item_links` entries **When** the editor parses it **Then** each item link block is correctly deserialized with name, item_pool, replacement_item, link_replacement, local_items, and non_local_items restored.

6. **Given** the item_links list is empty (no entries) **When** serialized **Then** `item_links` is omitted from the YAML output (same empty-array filter as plando_items).

## Tasks / Subtasks

- [x] Task 1 - Add `ItemLinksOption` type, parsing, serialization to `archipelago-yaml.ts` (AC: #2, #4, #5, #6)
  - [x] 1.1 Define `ItemLinkEntry` and `ItemLinksOption` types
  - [x] 1.2 Add `ItemLinksOption` to the `GameOption` union
  - [x] 1.3 Add `key === "item_links"` detection in `buildOption` BEFORE the `Array.isArray(value)` check (same insertion point as plando_items guard - add after it)
  - [x] 1.4 Implement `parseItemLinkEntries(value: unknown): ItemLinkEntry[]` helper
  - [x] 1.5 Add serialization branch in `serializeOption` for `opt.type === "item_links"`
  - [x] 1.6 Update `mergePlayerValues` to handle `item_links` type (replace entries wholesale)

- [x] Task 2 - Add `ItemLinksField` and `ItemLinkCard` components to `yaml-option-editor.tsx` (AC: #1, #2, #3)
  - [x] 2.1 Add dispatch in `OptionField` for `option.type === "item_links"`
  - [x] 2.2 Implement `ItemLinksField`: Simple mode → banner; Advanced mode → list + "Ajouter un lien" button
  - [x] 2.3 Implement `ItemLinkCard`: name input, item_pool list editor (with "Everything" checkbox), replacement_item field (null toggle + text input), link_replacement Yes/No, local_items list, non_local_items list
  - [x] 2.4 Stable `id`-based React keys on every entry and list item

- [x] Task 3 - Fix `admin-yaml-editor.tsx` narrowing (AC: all compile)
  - [x] 3.1 Add `ItemLinksOption` case in `displayValue`
  - [x] 3.2 Add `ItemLinksOption` case in `updateOptionValue`

- [x] Task 4 - Quality gates
  - [x] 4.1 `pnpm typecheck` → 0 errors
  - [x] 4.2 `pnpm lint` → 0 errors / 0 warnings
  - [x] 4.3 `pnpm build` → clean

## Dev Notes

### Same Bug as plando_items

`item_links` in the game block YAML is an array of objects. The generic `Array.isArray(value)` branch in `buildOption` fires → each element becomes `String(obj)` = `"[object Object]"`. Fix: insert `key === "item_links"` guard AFTER the existing `plando_items` guard (both are before `Array.isArray`).

---

### New Types (`frontend/src/lib/archipelago-yaml.ts`)

```typescript
export type ItemLinkEntry = {
  id: string;
  name: string;
  itemPool: string[];         // list of item names, or ["Everything"]
  replacementItem: string | null; // null = filler chosen by generator
  linkReplacement: boolean;   // default false
  localItems: string[];
  nonLocalItems: string[];
};

export type ItemLinksOption = {
  type: "item_links";
  key: string;
  label: string;
  entries: ItemLinkEntry[];
  description?: string;
  category?: string;
};
```

Update union:
```typescript
export type GameOption = ... | PlandoItemsOption | ItemLinksOption;
```

---

### Parsing (`parseItemLinkEntries`)

```typescript
function parseItemLinkEntries(value: unknown): ItemLinkEntry[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((raw) => {
    if (!raw || typeof raw !== "object") return [];
    const block = raw as Record<string, unknown>;

    const name = typeof block["name"] === "string" ? block["name"] : "";

    const poolRaw = block["item_pool"];
    const itemPool: string[] = Array.isArray(poolRaw)
      ? poolRaw.map((i) => (typeof i === "string" ? i : String(i ?? "")))
      : [];

    const repRaw = block["replacement_item"];
    const replacementItem = repRaw === null || repRaw === undefined
      ? null
      : typeof repRaw === "string" ? repRaw : null;

    const linkReplacement = block["link_replacement"] === true;

    const localItems: string[] = Array.isArray(block["local_items"])
      ? (block["local_items"] as unknown[]).map((i) => typeof i === "string" ? i : String(i ?? ""))
      : [];

    const nonLocalItems: string[] = Array.isArray(block["non_local_items"])
      ? (block["non_local_items"] as unknown[]).map((i) => typeof i === "string" ? i : String(i ?? ""))
      : [];

    return [{ id: uid(), name, itemPool, replacementItem, linkReplacement, localItems, nonLocalItems }];
  });
}
```

---

### Serialization (`serializeOption`)

```typescript
if (opt.type === "item_links") {
  return opt.entries
    .filter((e) => e.name.trim() !== "")
    .map((e) => {
      const block: Record<string, unknown> = { name: e.name.trim() };
      const pool = e.itemPool.filter((i) => i.trim() !== "");
      if (pool.length > 0) block["item_pool"] = pool;
      block["replacement_item"] = e.replacementItem;
      if (e.linkReplacement) block["link_replacement"] = true;
      if (e.localItems.length > 0) block["local_items"] = e.localItems.filter((i) => i.trim() !== "");
      if (e.nonLocalItems.length > 0) block["non_local_items"] = e.nonLocalItems.filter((i) => i.trim() !== "");
      return block;
    });
}
```

AC#6: same empty-array filter in `serializeToYaml` already handles this (added for plando_items in story 4.12).

---

### Merge Logic

```typescript
if (baseOpt.type === "item_links" && playerOpt.type === "item_links") {
  return { ...baseOpt, entries: playerOpt.entries };
}
```
Insert alongside the `plando_items` case in `mergePlayerValues`.

---

### UI (`yaml-option-editor.tsx`)

**`ItemLinksField`** - same structure as `PlandoItemsField`:
- Simple mode: `<p className="text-xs text-muted-foreground">Configurez les item links en mode <strong>Avancé</strong>.</p>`
- Advanced mode: list of `ItemLinkCard` + "Ajouter un lien" button (ADD_BTN_CLS)

**`ItemLinkCard`** fields (use `rounded-lg border border-border p-3 grid gap-4` card):

| Field | UI |
|-------|-----|
| **Nom du groupe** | Text input (INPUT_CLS), required - shared name links players together |
| **Item pool** | List of text inputs + Add button; checkbox "Everything" that adds/removes the string "Everything" from the list |
| **Replacement item** | Toggle "Aucun (filler auto)" / "Spécifier" - when "Spécifier", show text input |
| **Link replacement** | Yes/No toggle (same pattern as `SimpleToggle`) |
| **Local items** | List of text inputs + Add button |
| **Non-local items** | List of text inputs + Add button |

Place `ItemLinksField` and `ItemLinkCard` immediately after `PlandoEntryCard` and before `NumberStepper` in the file.

---

### `admin-yaml-editor.tsx` Fix

Same pattern as plando_items fix (story 4.12):

```typescript
// displayValue
if (option.type === "item_links") return `${option.entries.length} lien(s)`;

// updateOptionValue
if (option.type === "item_links") return option;
```

---

### Do NOT

- Do NOT add backend endpoints - pure frontend YAML editing
- Do NOT break existing behavior of `plando_items`, `FreeformListOption`, etc.
- Do NOT add new npm packages
- Do NOT create new files - all changes in the 3 existing files only
- Do NOT call `crypto.randomUUID()` during render

### Project Structure Notes

The `key === "item_links"` guard goes right after the `key === "plando_items"` guard in `buildOption` (both before `Array.isArray`). New components (`ItemLinksField`, `ItemLinkCard`) go immediately after `PlandoEntryCard` and before `NumberStepper`.

Reuse: `INPUT_CLS`, `REMOVE_BTN_CLS`, `ADD_BTN_CLS` constants, all design tokens.

### References

- [Source: frontend/src/lib/archipelago-yaml.ts] - insert after `plando_items` guard in `buildOption`, after plando case in `serializeOption` and `mergePlayerValues`
- [Source: frontend/src/features/events/yaml-option-editor.tsx] - insert after `PlandoEntryCard` block, add dispatch in `OptionField`
- [Source: frontend/src/features/admin/admin-yaml-editor.tsx] - same narrowing fix pattern as story 4.12
- [Source: Story 4.12] - `_bmad-output/implementation-artifacts/4-12-plando-items-advanced-configuration.md` - follow identical patterns for types, parsing, serialization, merge, UI structure
- [Archipelago Advanced Settings: https://archipelago.gg/tutorial/Archipelago/advanced_settings_en]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- Fixed same bug as plando_items: `item_links` parsed as `[object Object]` strings. Fixed by `key === "item_links"` guard before `Array.isArray` in `buildOption`.
- Added `ItemLinkEntry`, `ItemLinksOption` types; updated `GameOption` union.
- Implemented `parseItemLinkEntries`, serialization, and merge logic.
- Empty `item_links` arrays omitted via existing filter in `serializeToYaml` (added by story 4.12).
- Added `ItemLinksField` (mode-aware) and `ItemLinkCard` (6 fields: name, item_pool + Everything checkbox, replacement_item toggle, link_replacement, local_items, non_local_items).
- Fixed `admin-yaml-editor.tsx` narrowing for new type.
- All 6 ACs confirmed: banner in Simple mode, full card in Advanced mode, all fields present, quality gates green.

### File List

- frontend/src/lib/archipelago-yaml.ts
- frontend/src/features/events/yaml-option-editor.tsx
- frontend/src/features/admin/admin-yaml-editor.tsx
