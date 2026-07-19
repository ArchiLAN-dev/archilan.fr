/**
 * Turns a game name into the URL slug used by `/runs-hebdo/jeu/[gameSlug]`.
 *
 * Shared so the weekly-run page and the sitemap derive the slug the exact same way -
 * a sitemap slug that drifts from the page slug would list a route that renders the
 * empty state. NFD-normalise, lowercase, non-alphanumerics to a single `-`, trimmed.
 */
export function slugify(name: string): string {
  return name
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}
