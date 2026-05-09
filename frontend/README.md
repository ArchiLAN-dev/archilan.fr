# frontend

Next.js 16 frontend for archilan.fr - App Router, TypeScript, Tailwind CSS v4, shadcn/ui.

## Stack

- **Next.js 16** App Router with React 19
- **TypeScript** strict mode
- **Tailwind CSS v4** + shadcn/ui (radix-nova preset)
- **TanStack Query** for server state
- **next-themes** for dark mode
- **pnpm** as package manager

## Getting started

```bash
pnpm install
pnpm dev       # development server on http://localhost:3000
```

## Quality commands

```bash
pnpm lint       # ESLint
pnpm typecheck  # tsc --noEmit
pnpm build      # production build
```

## Notes

- `frontend/` is independently buildable and deployable.
- Environment variables go in `.env.local` (local only, gitignored). See `.env.example` for expected keys.
- This starter contains no ArchiLAN business UI. Product implementation starts after Epic 0 setup is complete.
