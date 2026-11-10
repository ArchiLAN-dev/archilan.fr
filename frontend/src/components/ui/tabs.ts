/**
 * Story 3.18: the look of the site's tabs (pelles, leaderboard, personal run, survey), shared so that new tab bars
 * match them. Class helpers, so a `<button>` or a `<Link>` can wear them.
 */
export const TAB_LIST_CLASS = "flex gap-1 overflow-x-auto border-b border-border [scrollbar-width:none]";

export function tabClassName(active: boolean): string {
  return `-mb-px inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-4 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 ${
    active ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"
  }`;
}
