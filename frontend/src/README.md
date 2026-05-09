# Frontend Source Boundaries

`src/app` owns Next.js routes and route-level metadata.

`src/features` owns domain-facing frontend modules. Feature modules may import shared UI, providers, types, and lib utilities, but shared UI must not import feature code.

`src/components` owns shared UI. `src/components/ui` is reserved for shadcn/ui primitives only.

`src/lib` owns framework-level helpers.

`src/providers` owns React provider composition.

`src/types` owns shared DTO and API types that mirror backend response contracts.

Do not add ArchiLAN product UI or business behavior in setup stories.
