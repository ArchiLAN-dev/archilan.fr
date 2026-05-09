# Story 11.2: IGDB Search Widget in Game Creation Form

Status: done

## Story

As an admin,
I want a search widget in the game creation form that queries IGDB and pre-fills the form fields on selection,
so that I can create games quickly without manually typing names, descriptions, and cover URLs.

## Acceptance Criteria

1. The game creation page (`/admin/jeux/nouveau`) displays an IGDB search input above the form fields, with placeholder "Rechercher sur IGDB…".
2. Typing in the search input triggers a debounced fetch (300 ms) to `GET /api/v1/admin/igdb/search?q={query}`. Fetching is aborted on each new keystroke via `AbortController`.
3. Results are displayed as a dropdown list below the search input. Each row shows the cover thumbnail (or a placeholder icon if `coverUrl` is null) and the game name.
4. Clicking a result pre-fills the form fields: `name` ← `name`, `description` ← `summary` (truncated to 500 chars if longer), `coverImageUrl` ← `coverUrl`, `coverImageCredit` ← `"© IGDB"`, `slug` ← slug auto-generated from `name` (lowercase, spaces to hyphens, strip accents and special chars). All fields remain manually editable after pre-fill.
5. While the search is loading, the dropdown shows a "Recherche en cours…" indicator.
6. If the search returns no results, the dropdown shows "Aucun résultat pour « {query} »".
7. If the search API returns an error (non-2xx or network failure), the dropdown shows "Erreur lors de la recherche IGDB." - the form remains fully functional without IGDB.
8. Clicking outside the search input or selecting a result closes the dropdown.
9. The IGDB search section is visually separated from the manual form fields (e.g., a section heading "Importer depuis IGDB" with a subtitle "Optionnel - les champs restent modifiables après import").
10. The widget does not prevent form submission - if IGDB is unused or unavailable, the form works exactly as before.

## Tasks / Subtasks

- [x] Create `frontend/src/features/admin/igdb-game-search.tsx` (AC: 1-9)
  - [x] `IgdbGameSearch` component props: `onSelect: (result: IgdbResult) => void`
  - [x] `IgdbResult` type: `{ igdbId: number; name: string; slug: string; summary: string | null; coverUrl: string | null }`
  - [x] State: `query`, `results`, `status: "idle" | "loading" | "error" | "done"`, `open`
  - [x] Debounce with `useRef` timer (300 ms) + `AbortController` per fetch
  - [x] Dropdown: positioned below input, `z-50`, closes on outside click (`useEffect` document listener) and on `Escape`
  - [x] Result row: `<img>` 40×56 thumbnail (`object-cover`) or `<Gamepad2>` icon fallback, game name
  - [x] Empty state, loading state, error state inside the dropdown

- [x] Update `frontend/src/app/(admin)/admin/jeux/nouveau/page.tsx` (AC: 1, 4, 9, 10)
  - [x] Import `IgdbGameSearch` and add above the form
  - [x] Section wrapper: heading "Importer depuis IGDB" + subtitle
  - [x] `handleIgdbSelect(result)`: update form field values - name, description (slice to 500), coverImageUrl, coverImageCredit ("© IGDB"), slug (slugify name)
  - [x] Slugify helper: `toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')`
  - [x] Pass pre-filled values as controlled inputs

- [x] Convert `/admin/jeux/nouveau/page.tsx` form inputs to controlled (AC: 4)
  - [x] Add `useState` for each field (name, slug, description, coverImageUrl, coverImageAlt, coverImageCredit, availability, supportedEventTypes)
  - [x] Bind `value`/`onChange` instead of `defaultValue` - preserves existing validation and submit logic

## Dev Notes

### Project Structure
- New file: `frontend/src/features/admin/igdb-game-search.tsx`
- Modified: `frontend/src/app/(admin)/admin/jeux/nouveau/page.tsx` (add widget, convert to controlled)

### API base URL
Always use `env.apiBaseUrl` from `@/lib/env` - never hardcode the URL.

### Debounce + abort pattern
```tsx
const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
const abortRef = useRef<AbortController | null>(null);

function handleQueryChange(q: string) {
  setQuery(q);
  if (timerRef.current) clearTimeout(timerRef.current);
  abortRef.current?.abort();
  if (!q.trim()) { setStatus("idle"); setResults([]); return; }

  timerRef.current = setTimeout(() => {
    abortRef.current = new AbortController();
    void fetchResults(q, abortRef.current.signal);
  }, 300);
}
```

### Outside-click close
```tsx
useEffect(() => {
  function handleClick(e: MouseEvent) {
    if (!containerRef.current?.contains(e.target as Node)) setOpen(false);
  }
  document.addEventListener("mousedown", handleClick);
  return () => document.removeEventListener("mousedown", handleClick);
}, []);
```

### Slug generation helper
```ts
function slugify(name: string): string {
  return name
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
}
```

### IGDB cover thumbnail
Use `coverUrl` directly from the API (already `t_cover_big`). For the dropdown rows, display at `w-10 h-14` (`40×56`px) with `object-cover rounded`. If null, render `<Gamepad2 className="size-8 text-muted-foreground" />` centered in a same-size placeholder.

### Form controlled conversion
The current `nouveau/page.tsx` uses uncontrolled inputs with `defaultValue`. Converting to controlled:
- Add `const [fields, setFields] = useState({ name: "", slug: "", ... })`
- Replace `defaultValue={x}` with `value={fields.x} onChange={e => setFields(f => ({...f, x: e.target.value}))}`
- `handleIgdbSelect` calls `setFields({...})` to inject IGDB data

### Dependencies
Story 11.1 must be deployed (or mocked) before Story 11.2 can be tested end-to-end. For frontend dev, mock the API response with a static JSON fixture.

### No new dependencies
No new npm packages needed - debounce is implemented manually with `useRef`+`setTimeout`.

## Dev Agent Record

### Agent Model Used
claude-sonnet-4-6

### Debug Log References
- `coverImageUrl` is not yet in the backend game model; field was added to the creation form anyway so IGDB pre-fill works visually and the backend ignores it gracefully

### Completion Notes List
- `IgdbGameSearch` fetches `${env.apiBaseUrl}/admin/igdb/search?q=` (proxy from story 11-1)
- Dropdown uses `z-50`, closes on Escape and outside mousedown
- `slugify` uses `normalize("NFD") + ̀-ͯ` unicode range
- Form fully converted from uncontrolled to controlled (`Fields` state object)
- TypeScript strict check passes - 0 errors

### File List
- frontend/src/features/admin/igdb-game-search.tsx (new)
- frontend/src/app/(admin)/admin/jeux/nouveau/page.tsx (rewritten)
