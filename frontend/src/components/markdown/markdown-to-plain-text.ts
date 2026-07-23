/**
 * Flattens markdown to plain text for places that must not show syntax (story 10.10).
 *
 * `generateMetadata` on the event and game pages feeds the description straight into
 * `<meta name="description">` and the OG card, so `**gras**` or `## Titre` would leak as-is.
 * This is a deliberately small, dependency-free projection - it is never used to render to the
 * page, only to produce a text summary, so it does not need to be a full markdown parser.
 */
export function markdownToPlainText(markdown: string | null | undefined): string {
  if (markdown === null || markdown === undefined) return "";

  return (
    markdown
      // Fenced then inline code: keep the code text, drop the markers.
      .replace(/```[a-z]*\n?([\s\S]*?)```/gi, "$1")
      .replace(/`([^`]+)`/g, "$1")
      // Images before links: ![alt](url) keeps the alt text only.
      .replace(/!\[([^\]]*)\]\([^)]*\)/g, "$1")
      .replace(/\[([^\]]+)\]\([^)]*\)/g, "$1")
      // Leading block markers: headings, quotes, list bullets.
      .replace(/^\s{0,3}#{1,6}\s+/gm, "")
      .replace(/^\s{0,3}>\s?/gm, "")
      .replace(/^\s{0,3}[-*+]\s+/gm, "")
      .replace(/^\s{0,3}\d+\.\s+/gm, "")
      // Horizontal rules.
      .replace(/^\s{0,3}([-*_])\s*(?:\1\s*){2,}$/gm, "")
      // Emphasis markers, innermost first so ***both*** unwraps cleanly.
      .replace(/(\*\*\*|___)(.+?)\1/g, "$2")
      .replace(/(\*\*|__)(.+?)\1/g, "$2")
      .replace(/(\*|_)(.+?)\1/g, "$2")
      .replace(/~~(.+?)~~/g, "$1")
      // Collapse the whitespace the markers left behind.
      .replace(/\s+/g, " ")
      .trim()
  );
}
