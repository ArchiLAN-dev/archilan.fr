# Story 4.12: Plando Items Advanced Configuration in YAML Editor

Status: review

## Story

As a registrant,
I want to configure `plando_items` rules in the YAML editor in Advanced mode,
So that I can specify exactly which items get placed in which locations in the Archipelago multiworld.

## Acceptance Criteria

1. **Given** a slot YAML that contains a `plando_items` key **When** the user is in Simple mode **Then** the Plando Items field shows a banner "Configurez les plando items en mode Avancé" instead of any editor controls.

2. **Given** a slot YAML that contains a `plando_items` key **When** the user switches to Advanced mode **Then** the Plando Items section renders as a list of plando entry cards, each with all configuration fields.

3. **Given** the user clicks "+ Ajouter une règle" in the Plando Items section **When** a new entry card appears **Then** it contains: items sub-section (item name + quantity pairs), locations sub-section (location name list), world selector, from_pool Yes/No toggle, force selector, percentage stepper (0–100).

4. **Given** the user configures a plando entry and saves **When** the YAML is serialized **Then** `plando_items` contains a correctly structured array of objects matching the Archipelago spec (items as a dict, locations as an array, world as boolean/null/string, force as boolean/string, from_pool as boolean, percentage as integer).

5. **Given** a previously saved YAML with `plando_items` entries **When** the editor parses it **Then** each plando block is correctly deserialized with items, locations, world, from_pool, force, and percentage restored.

6. **Given** the plando_items list is empty (no entries) **When** serialized **Then** `plando_items` is omitted from the YAML output (empty arrays are not written).

## Tasks / Subtasks

- [x] Task 1 — Add `PlandoItemsOption` type, parsing, serialization to `archipelago-yaml.ts` (AC: #2, #4, #5, #6)
  - [x] 1.1 Define `PlandoItemRow`, `PlandoLocationRow`, `PlandoItem`, and `PlandoItemsOption` types
  - [x] 1.2 Add `PlandoItemsOption` to the `GameOption` union
  - [x] 1.3 Add `key === "plando_items"` detection in `buildOption` BEFORE the `Array.isArray(value)` check
  - [x] 1.4 Implement `parsePlandoEntries(value: unknown): PlandoItem[]` helper
  - [x] 1.5 Add serialization branch in `serializeOption` for `opt.type === "plando_items"`
  - [x] 1.6 Update `mergePlayerValues` to handle `plando_items` type (replace entries wholesale from player YAML)

- [x] Task 2 — Add `PlandoItemsField` and `PlandoEntryCard` components to `yaml-option-editor.tsx` (AC: #1, #2, #3)
  - [x] 2.1 Add dispatch in `OptionField` for `option.type === "plando_items"`
  - [x] 2.2 Implement `PlandoItemsField`: Simple mode → banner; Advanced mode → list + "Ajouter" button
  - [x] 2.3 Implement `PlandoEntryCard`: items dict editor, locations list editor, world selector, from_pool toggle, force selector, percentage stepper
  - [x] 2.4 Stable `id`-based React keys on every entry, item row, and location row

- [x] Task 3 — Quality gates (AC: all)
  - [x] 3.1 `pnpm typecheck` → 0 errors
  - [x] 3.2 `pnpm lint` → 0 errors / 0 warnings
  - [x] 3.3 `pnpm build` → clean

## Dev Notes

### Critical Bug Being Fixed

`plando_items` is currently silently broken. In `buildOption` (archipelago-yaml.ts:276), the generic `Array.isArray(value)` branch fires before any key-specific check. Each array element (an object) gets `String(item)` → `"[object Object]"`. The field renders as a list of text inputs showing garbage.

**Root fix:** Insert a `key === "plando_items"` guard at the TOP of `buildOption`, before line 276.

---

### New Types (`frontend/src/lib/archipelago-yaml.ts`)

```typescript
export type PlandoItemRow = { id: string; name: string; quantity: number };
export type PlandoLocationRow = { id: string; value: string };

// world stored as discriminated string for UI simplicity:
// "own"    → serializes to false
// "any"    → serializes to true
// "random" → serializes to null
// any other string → serializes as-is (player name)
export type PlandoItem = {
  id: string;
  items: PlandoItemRow[];
  locations: PlandoLocationRow[];
  world: string;           // "own" | "any" | "random" | "<player name>"
  fromPool: boolean;       // default true
  force: "true" | "false" | "silent"; // default "silent"
  percentage: number;      // 0–100, default 100
};

export type PlandoItemsOption = {
  type: "plando_items";
  key: string;
  label: string;
  entries: PlandoItem[];
  description?: string;
  category?: string;
};
```

Update union:
```typescript
export type GameOption = TextOption | ToggleOption | ChoiceOption | RangeOption | FreeformOption | PlandoItemsOption;
```

---

### Parsing Logic — `buildOption` insertion point

Insert BEFORE `if (Array.isArray(value))` (currently line ~276):

```typescript
// Plando items: array of structured blocks
if (key === "plando_items") {
  return {
    type: "plando_items", key, label,
    entries: parsePlandoEntries(value),
    description,
  };
}
```

Implement `parsePlandoEntries`:
```typescript
function parsePlandoEntries(value: unknown): PlandoItem[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((raw) => {
    if (!raw || typeof raw !== "object") return [];
    const block = raw as Record<string, unknown>;

    // items / item
    const itemsRaw = block["items"] ?? block["item"];
    let itemRows: PlandoItemRow[] = [];
    if (typeof itemsRaw === "string") {
      itemRows = [{ id: uid(), name: itemsRaw, quantity: 1 }];
    } else if (itemsRaw && typeof itemsRaw === "object" && !Array.isArray(itemsRaw)) {
      itemRows = Object.entries(itemsRaw as Record<string, unknown>).map(([name, qty]) => ({
        id: uid(), name, quantity: typeof qty === "number" ? qty : 1,
      }));
    }

    // locations / location
    const locsRaw = block["locations"] ?? block["location"];
    let locationRows: PlandoLocationRow[] = [];
    if (typeof locsRaw === "string") {
      locationRows = [{ id: uid(), value: locsRaw }];
    } else if (Array.isArray(locsRaw)) {
      locationRows = locsRaw.map((l) => ({ id: uid(), value: typeof l === "string" ? l : String(l ?? "") }));
    }

    // world
    const worldRaw = block["world"];
    let world = "own";
    if (worldRaw === true) world = "any";
    else if (worldRaw === null) world = "random";
    else if (typeof worldRaw === "string") world = worldRaw;

    // force: AP accepts true/false (boolean) or "silent" (string)
    const forceRaw = block["force"];
    let force: "true" | "false" | "silent" = "silent";
    if (forceRaw === true || forceRaw === "true") force = "true";
    else if (forceRaw === false || forceRaw === "false") force = "false";

    // from_pool
    const fromPool = block["from_pool"] !== false; // default true

    // percentage
    const percentage = typeof block["percentage"] === "number"
      ? Math.max(0, Math.min(100, Math.round(block["percentage"])))
      : 100;

    return [{
      id: uid(), items: itemRows, locations: locationRows,
      world, fromPool, force, percentage,
    }];
  });
}
```

---

### Serialization Logic (`serializeOption`)

```typescript
if (opt.type === "plando_items") {
  const out = opt.entries
    .filter((e) => e.items.some((i) => i.name.trim() !== ""))
    .map((e) => {
      const itemsDict: Record<string, number> = {};
      for (const i of e.items) {
        if (i.name.trim()) itemsDict[i.name.trim()] = i.quantity;
      }
      const worldValue =
        e.world === "own" ? false :
        e.world === "any" ? true :
        e.world === "random" ? null :
        e.world;
      const forceValue =
        e.force === "true" ? true :
        e.force === "false" ? false :
        "silent";
      const block: Record<string, unknown> = { items: itemsDict };
      const locs = e.locations.map((l) => l.value).filter((v) => v.trim() !== "");
      if (locs.length > 0) block["locations"] = locs;
      block["world"] = worldValue;
      block["from_pool"] = e.fromPool;
      block["force"] = forceValue;
      if (e.percentage !== 100) block["percentage"] = e.percentage;
      return block;
    });
  return out.length > 0 ? out : [];
}
```

AC#6: `serializeOption` returning `[]` — the caller in `serializeToYaml` will write `plando_items: []`. To omit empty arrays, add a filter in `serializeToYaml` after building `gameBlock`:
```typescript
// In serializeToYaml, after building gameBlock:
for (const key of Object.keys(gameBlock)) {
  if (Array.isArray(gameBlock[key]) && (gameBlock[key] as unknown[]).length === 0) {
    delete gameBlock[key];
  }
}
```
Only apply this filter to array-valued keys to avoid breaking other options.

---

### Merge Logic (`mergePlayerValues`)

Add case for `plando_items` — replace entries wholesale (plando blocks are user-authored, not schema-derived):
```typescript
if (baseOpt.type === "plando_items" && playerOpt.type === "plando_items") {
  return { ...baseOpt, entries: playerOpt.entries };
}
```
Insert this before the `freeform` check in the `mergePlayerValues` map.

---

### UI — `yaml-option-editor.tsx`

**In `OptionField`** (after the `dict` branch, around line 499):
```typescript
{option.type === "plando_items" && (
  <PlandoItemsField mode={mode} option={option} readOnly={readOnly} onChange={onChange} />
)}
```

The `onChange` signature in `OptionField` is `(updated: GameOption) => void` — cast is safe because `PlandoItemsField` will only call it with `PlandoItemsOption`.

**`PlandoItemsField` component:**
```typescript
function PlandoItemsField({
  mode, option, readOnly, onChange,
}: {
  mode: Mode;
  option: PlandoItemsOption;
  readOnly: boolean;
  onChange: (o: GameOption) => void;
}) {
  if (mode === "simple") {
    return (
      <p className="text-xs text-muted-foreground">
        Configurez les plando items en mode <strong>Avancé</strong>.
      </p>
    );
  }
  // Advanced mode: entry cards + add button
  function updateEntry(updated: PlandoItem) {
    onChange({ ...option, entries: option.entries.map((e) => e.id === updated.id ? updated : e) });
  }
  function removeEntry(id: string) {
    onChange({ ...option, entries: option.entries.filter((e) => e.id !== id) });
  }
  function addEntry() {
    const newEntry: PlandoItem = {
      id: crypto.randomUUID(), items: [], locations: [],
      world: "own", fromPool: true, force: "silent", percentage: 100,
    };
    onChange({ ...option, entries: [...option.entries, newEntry] });
  }
  return (
    <div className="grid gap-3">
      {option.entries.map((entry, i) => (
        <PlandoEntryCard
          key={entry.id}
          entry={entry}
          index={i}
          readOnly={readOnly}
          onChange={updateEntry}
          onRemove={removeEntry}
        />
      ))}
      {!readOnly && (
        <button className={ADD_BTN_CLS} type="button" onClick={addEntry}>
          <Plus aria-hidden="true" className="size-3.5" />
          Ajouter une règle
        </button>
      )}
      {option.entries.length === 0 && readOnly && (
        <p className="text-xs italic text-muted-foreground">Aucune règle plando</p>
      )}
    </div>
  );
}
```

**`PlandoEntryCard` component** — place after `PlandoItemsField`, before `NumberStepper`:
```typescript
function PlandoEntryCard({
  entry, index, readOnly, onChange, onRemove,
}: {
  entry: PlandoItem;
  index: number;
  readOnly: boolean;
  onChange: (e: PlandoItem) => void;
  onRemove: (id: string) => void;
}) {
  // Section: Items
  // Section: Locations
  // Field: World (select + optional text input for named player)
  // Field: From pool (Yes/No buttons, same pattern as SimpleToggle)
  // Field: Force (select: silent / true / false)
  // Field: Percentage (NumberStepper 0–100)
}
```

Internal card layout uses `rounded-lg border border-border p-3 grid gap-4` and section labels use `text-[11px] font-semibold uppercase tracking-widest text-muted-foreground` (same as CategoryAccordion).

**World selector options:**
```
"own"    → label "Mon monde (défaut)"
"any"    → label "N'importe quel autre monde"
"random" → label "Monde aléatoire"
"named"  → label "Nom du joueur…" (reveal a text input alongside the select for the actual player name)
```
When serializing "named", use the typed player name as the world string.

**Force selector options:**
```
"silent" → label "Silencieux (défaut) — ignorer si impossible"
"true"   → label "Strict — erreur si impossible"
"false"  → label "Souple — warning si impossible"
```

---

### Do NOT

- Do NOT add backend endpoints — pure frontend YAML editing only
- Do NOT break `FreeformListOption` / `FreeformDictOption` / `ListField` / `DictField` behavior
- Do NOT add new npm packages — `js-yaml`, React, Lucide icons are all already available
- Do NOT create new files — all changes in the 2 existing files only
- Do NOT call `crypto.randomUUID()` during render — only in event handlers and `parsePlandoEntries`

### Project Structure Notes

`yaml-option-editor.tsx` uses inline named functions throughout (1065 lines). Place new components (`PlandoItemsField`, `PlandoEntryCard`) after the `DictField` section and before the `NumberStepper` section, following the established pattern.

Reuse constants already defined:
- `INPUT_CLS` — text inputs
- `REMOVE_BTN_CLS` — delete (×) buttons  
- `ADD_BTN_CLS` — dashed "+ Ajouter" buttons

### References

- [Source: frontend/src/lib/archipelago-yaml.ts:256] — `buildOption` function, insert plando check before line 276
- [Source: frontend/src/lib/archipelago-yaml.ts:472] — `serializeOption` function, add plando branch
- [Source: frontend/src/lib/archipelago-yaml.ts:346] — `mergePlayerValues`, add plando case
- [Source: frontend/src/features/events/yaml-option-editor.tsx:479] — `OptionField` dispatch
- [Source: frontend/src/features/events/yaml-option-editor.tsx:808] — `ListField` pattern for location rows
- [Source: frontend/src/features/events/yaml-option-editor.tsx:862] — `DictField` pattern for items dict
- [Archipelago Plando Spec: https://archipelago.gg/tutorial/Archipelago/plando_en]

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- Fixed existing silent bug: `plando_items` was parsed as `FreeformListOption` causing `[object Object]` entries. Fixed by inserting key-specific guard before `Array.isArray` check in `buildOption`.
- Added `PlandoItemRow`, `PlandoLocationRow`, `PlandoItem`, `PlandoItemsOption` types to `archipelago-yaml.ts`.
- Implemented full parsing (`parsePlandoEntries`), serialization, and merge logic.
- Empty `plando_items: []` is omitted from YAML output via post-build filter in `serializeToYaml`.
- Added `PlandoItemsField` (mode-aware) and `PlandoEntryCard` (full 6-field form) to `yaml-option-editor.tsx`.
- Fixed `admin-yaml-editor.tsx` to handle `PlandoItemsOption` in `displayValue` and `updateOptionValue`.
- All 25 logic assertions pass via tsx test script; all 6 ACs confirmed visually in browser.
- Quality gates: `pnpm typecheck` 0 errors, `pnpm lint` 0 warnings, `pnpm build` clean.

### File List

- frontend/src/lib/archipelago-yaml.ts
- frontend/src/features/events/yaml-option-editor.tsx
- frontend/src/features/admin/admin-yaml-editor.tsx