import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";

/**
 * The panels of the admin user sheet (story 36.7), in page order. Their ids are the anchors of the
 * summary under the header, so each panel component uses the one listed here.
 */
export const SHEET_SECTIONS = [
  { id: "identite", label: "Identité" },
  { id: "acces", label: "Accès et rôles" },
  { id: "moderation", label: "Modération" },
  { id: "adhesion", label: "Adhésion" },
  { id: "inscriptions", label: "Inscriptions" },
  { id: "jeu", label: "Jeu" },
  { id: "journal", label: "Journal d'activité" },
] as const;

export type SheetSectionId = (typeof SHEET_SECTIONS)[number]["id"];

type SheetSectionProps = {
  id: SheetSectionId;
  title: string;
  icon: LucideIcon;
  /** One sentence on what the panel answers, under the title. */
  description?: string;
  children: ReactNode;
};

/**
 * One panel of the sheet: a rule above it, and on wide screens its title and help in a column of their
 * own, so where a panel ends is visible at a glance.
 */
export function SheetSection({ id, title, icon: Icon, description, children }: SheetSectionProps) {
  const titleId = `${id}-title`;

  return (
    <section
      aria-labelledby={titleId}
      className="grid scroll-mt-6 gap-4 border-t border-border pt-8 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-10"
      id={id}
    >
      <header className="grid content-start gap-1">
        <h2 className="flex items-center gap-2 font-heading text-lg font-semibold text-foreground" id={titleId}>
          <Icon aria-hidden className="size-5 shrink-0 text-accent-text" />
          {title}
        </h2>
        {description !== undefined ? <p className="text-sm text-muted-foreground">{description}</p> : null}
      </header>
      <div className="grid min-w-0 content-start gap-4">{children}</div>
    </section>
  );
}

/** The summary under the sheet's header: one link per panel, scrolling sideways on a phone. */
export function SheetNav() {
  return (
    <nav aria-label="Sections de la fiche" className="-mx-1 overflow-x-auto px-1 pb-1 [scrollbar-width:none]">
      <ul className="flex gap-2" role="list">
        {SHEET_SECTIONS.map((section) => (
          <li className="shrink-0" key={section.id}>
            <a
              className="inline-flex min-h-8 items-center rounded-full border border-border px-3 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
              href={`#${section.id}`}
            >
              {section.label}
            </a>
          </li>
        ))}
      </ul>
    </nav>
  );
}

/** The panel's content when a list is empty: a dashed frame, lighter than a filled card. */
export function SheetEmpty({ children }: { children: ReactNode }) {
  return <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">{children}</p>;
}

/** Class of a list that holds its rows in one card, separated by a line. */
export const SHEET_LIST_CLASS = "divide-y divide-border rounded-lg border border-border bg-surface";
