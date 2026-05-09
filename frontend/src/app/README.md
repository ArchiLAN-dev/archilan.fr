# App Routes

`app/layout.tsx` applies the public `PublicShell` to current public routes.

When backoffice routes are introduced, create `app/admin/layout.tsx` and wrap admin pages in an `AdminShell` so authenticated administration never inherits public navigation.
