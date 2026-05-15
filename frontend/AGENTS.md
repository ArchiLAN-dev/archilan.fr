<!-- BEGIN:nextjs-agent-rules -->
# This is NOT the Next.js you know

This version has breaking changes - APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` before writing any code. Heed deprecation notices.
<!-- END:nextjs-agent-rules -->

---

# Frontend — TypeScript / React / Next.js Standards

## Quality gates (non-negotiable)

```bash
pnpm typecheck   # tsc --noEmit — 0 errors
pnpm lint        # eslint — 0 errors, 0 warnings
pnpm build       # next build — clean
```

Run all three before marking any task complete.

---

## TypeScript standards

**AC-TS1:** `strict: true` is active in `tsconfig.json`. Zero tolerance for type errors.  
**AC-TS2:** Never use `any`. If unavoidable, add an `// eslint-disable-next-line @typescript-eslint/no-explicit-any` comment with a one-line explanation.  
**AC-TS3:** Never use `as SomeType` at API boundaries. All API responses are `unknown` until validated by a type guard function (`function isX(v: unknown): v is X`).  
**AC-TS4:** Type guards live in the same file as their `fetch*` function — always `is{TypeName}` naming.  
**AC-TS5:** Prefer `type` over `interface` for plain data shapes. Use `interface` only when you need declaration merging.

---

## Env variables

**AC-ENV1:** Never access `process.env` directly. Always go through `src/lib/env.ts`.  
**AC-ENV2:** Adding a new env variable requires: (1) add to `env.ts`, (2) add to `.env.example` if it exists, (3) document in the story's Dev Notes.

---

## Next.js App Router conventions

**AC-NX1:** Data fetching happens in **Server Components** (async functions). Never use `useEffect` to fetch initial data.  
**AC-NX2:** `route.ts` params are `Promise<{...}>` in Next.js 15 — always `await params` before destructuring.  
**AC-NX3:** Use `React.cache()` to deduplicate fetch calls between `generateMetadata` and the page component.  
**AC-NX4:** `"use client"` is added only when the component requires: event handlers, browser APIs, or TanStack Query hooks. Prefer Server Components by default.  
**AC-NX5:** `notFound()` is called for 404 cases — never return a rendered "not found" JSX from the page function.  
**AC-NX6:** `generateMetadata` must set `title`, `description`, and `openGraph.title` at minimum.

---

## Component design

**AC-CO1:** Components are pure functions of their props. A component must produce the same JSX for the same props — no side effects during render.  
**AC-CO2:** Props types are defined as a local `type Props = {...}` above the component, or inline. Never leave a component without explicit prop types.  
**AC-CO3:** No default exports for components inside `features/`. Use named exports. Default exports are reserved for Next.js page routes (`app/**/page.tsx`, `app/**/layout.tsx`).  
**AC-CO4:** Do not pass raw domain types from server to client as props if they contain non-serializable values (Dates, Maps, class instances). Serialize to plain objects first.

---

## React hooks — side effect discipline

**AC-HK1:** `useEffect` exhaustive-deps rule is enforced by ESLint. Never suppress it without understanding why.  
**AC-HK2:** No synchronous `setState` at the top level of a `useEffect` body. Mutations happen inside async callbacks or `requestAnimationFrame` callbacks only.  
**AC-HK3:** Impure functions (`Date.now()`, `Math.random()`, `crypto.randomUUID()`) MUST NOT be called during render — including inside `useQuery` options, `useMemo` deps, or conditional expressions in JSX. Compute them outside the component (server-side, in event handlers, or in `useRef` init).  
**AC-HK4:** Custom hooks are named `use{Noun}` and must follow the rules of hooks (no conditional calls, no loops).  
**AC-HK5:** TanStack Query's `initialDataUpdatedAt` must be set from a server-computed timestamp passed as a prop, never from `Date.now()` inside the client component.

---

## API layer

**AC-API1:** All fetch functions live in `src/features/{module}/{module}-api.ts`. No fetch calls inside components or hooks.  
**AC-API2:** Every fetch function returns a typed result or `null` — never throws to the caller. Network errors and non-OK responses are caught and return `null`.  
**AC-API3:** All API base URLs are read from `env.apiBaseUrl`. No hardcoded strings like `http://localhost:8080`.  
**AC-API4:** TanStack Query is used for all client-side data fetching. No raw `fetch` inside `useEffect`.  
**AC-API5:** `staleTime` is set explicitly on every `useQuery` call — never rely on the default (0ms causes unnecessary refetches).

---

## Keys and lists

**AC-KEY1:** List keys MUST be stable and unique. Never use array index as a key for lists that can be reordered or filtered.  
**AC-KEY2:** When mapping server data, use a natural unique identifier (`id`, `slug`). When one entity can appear multiple times (e.g. player with multiple slots in the same session), use a composite key (`${sessionId}-${game}`).

---

## State management

**AC-ST1:** No global mutable state outside of React Context or a Zustand store.  
**AC-ST2:** Server state (API data) lives in TanStack Query. Local UI state (open/closed, current tab) lives in `useState`. Never mix the two.  
**AC-ST3:** `useRef` is for DOM references and mutable values that should NOT trigger a re-render (e.g. animation frame IDs, previous values). Not for data that affects the UI.

---

## Styling

**AC-CSS1:** All styles use Tailwind utility classes. No inline `style={{}}` objects except for dynamic values that Tailwind cannot express (e.g. CSS custom property values, `maskImage`).  
**AC-CSS2:** Design tokens are used for colors and spacing: `bg-surface`, `border-border`, `text-foreground`, `text-muted-foreground`, `text-accent-text`, `font-heading`. Never hardcode hex colors.  
**AC-CSS3:** Mobile-first. Default styles target small screens; `sm:`, `md:`, `lg:` prefixes override for larger viewports.

---

## Forbidden patterns (never do these)

```tsx
// ❌ process.env directly
const url = process.env.NEXT_PUBLIC_API_URL;
// ✅ use env.ts
const url = env.apiBaseUrl;

// ❌ as cast at API boundary
const data = (await res.json()) as LeaderboardResponse;
// ✅ type guard
const payload: unknown = await res.json();
if (!isLeaderboardResponse(payload)) return null;

// ❌ Date.now() during render
useQuery({ initialDataUpdatedAt: Date.now() }); // react-hooks/purity violation
// ✅ pass from server
<ClientComponent initialDataFetchedAt={fetchedAt} />

// ❌ fetch in useEffect
useEffect(() => { fetch('/api/data').then(setData); }, []);
// ✅ TanStack Query
const { data } = useQuery({ queryKey: ['data'], queryFn: fetchData });

// ❌ array index as key
items.map((item, i) => <Row key={i} {...item} />);
// ✅ stable identifier
items.map((item) => <Row key={item.id} {...item} />);

// ❌ useState for server data
const [profile, setProfile] = useState(null);
useEffect(() => { fetchProfile(slug).then(setProfile); }, [slug]);
// ✅ Server Component or TanStack Query
const profile = await getPlayerProfile(slug); // Server Component
```
