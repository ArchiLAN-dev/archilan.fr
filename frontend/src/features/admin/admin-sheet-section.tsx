import type { KeyboardEvent, ReactNode } from "react";
import { CreditCard, Gamepad2, History, IdCard, Shield, type LucideIcon } from "lucide-react";

/**
 * The panels of the admin user sheet (story 36.7), in page order. Each panel component uses the id listed
 * here: it is the panel's anchor, and an old link to it (`#pelles`) opens the tab holding it (story 36.8).
 */
export const SHEET_SECTIONS = [
  { id: "identite", label: "Identité" },
  { id: "acces", label: "Accès et rôles" },
  { id: "moderation", label: "Modération" },
  { id: "adhesion", label: "Adhésion" },
  { id: "inscriptions", label: "Inscriptions" },
  { id: "jeu", label: "Jeu" },
  { id: "pelles", label: "Pelles" },
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
 * One panel of the sheet: a rule above it (except the first of its tab, right under the tab bar), and on
 * wide screens its title and help in a column of their own, so where a panel ends is visible at a glance.
 */
export function SheetSection({ id, title, icon: Icon, description, children }: SheetSectionProps) {
  const titleId = `${id}-title`;

  return (
    <section
      aria-labelledby={titleId}
      className="grid scroll-mt-6 gap-4 border-t border-border pt-8 first:border-t-0 first:pt-0 lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-10"
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

/**
 * The tabs of the sheet (story 36.8): the panels grouped by what an admin comes to look at. Each panel
 * belongs to exactly one tab, in page order.
 */
export const SHEET_TABS = [
  { id: "compte", label: "Compte", icon: IdCard, sections: ["identite", "acces"] },
  { id: "moderation", label: "Modération", icon: Shield, sections: ["moderation"] },
  { id: "association", label: "Association", icon: CreditCard, sections: ["adhesion", "inscriptions"] },
  { id: "jeu", label: "Jeu et pelles", icon: Gamepad2, sections: ["jeu", "pelles"] },
  { id: "journal", label: "Journal", icon: History, sections: ["journal"] },
] as const satisfies readonly { id: string; label: string; icon: LucideIcon; sections: readonly SheetSectionId[] }[];

export type SheetTabId = (typeof SHEET_TABS)[number]["id"];

/** The query parameter that holds the open tab, so a reload or a shared link opens the same one. */
export const SHEET_TAB_PARAM = "onglet";

/**
 * The tab to open: the one named in the address, else the one holding the section an old anchor points to
 * (`#pelles`), else « Compte ».
 */
export function resolveSheetTab(param: string | null, hash: string): SheetTabId {
  const byParam = SHEET_TABS.find((tab) => tab.id === param);
  if (byParam !== undefined) return byParam.id;
  const section = hash.replace(/^#/, "");
  const byAnchor = SHEET_TABS.find((tab) => (tab.sections as readonly string[]).includes(section));
  return byAnchor?.id ?? SHEET_TABS[0].id;
}

/** The tab bar under the sheet's header, scrolling sideways on a phone; the arrow keys move between tabs. */
export function SheetTabs({ active, onSelect }: { active: SheetTabId; onSelect: (tab: SheetTabId) => void }) {
  function onKeyDown(event: KeyboardEvent<HTMLDivElement>): void {
    if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") return;
    event.preventDefault();
    const index = SHEET_TABS.findIndex((tab) => tab.id === active);
    const next = SHEET_TABS[(index + (event.key === "ArrowRight" ? 1 : -1) + SHEET_TABS.length) % SHEET_TABS.length];
    onSelect(next.id);
    document.getElementById(`sheet-tab-${next.id}`)?.focus();
  }

  return (
    <div
      aria-label="Sections de la fiche"
      className="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 [scrollbar-width:none]"
      onKeyDown={onKeyDown}
      role="tablist"
    >
      {SHEET_TABS.map(({ id, label, icon: Icon }) => (
        <button
          aria-controls={`sheet-panel-${id}`}
          aria-selected={id === active}
          className={`-mb-px inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-4 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 ${
            id === active ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"
          }`}
          id={`sheet-tab-${id}`}
          key={id}
          onClick={() => onSelect(id)}
          role="tab"
          tabIndex={id === active ? 0 : -1}
          type="button"
        >
          <Icon aria-hidden className="size-4 shrink-0" />
          {label}
        </button>
      ))}
    </div>
  );
}

/** The content of the open tab, labelled by its tab. */
export function SheetTabPanel({ tab, children }: { tab: SheetTabId; children: ReactNode }) {
  return (
    <div aria-labelledby={`sheet-tab-${tab}`} className="grid gap-8" id={`sheet-panel-${tab}`} role="tabpanel">
      {children}
    </div>
  );
}

/** The panel's content when a list is empty: a dashed frame, lighter than a filled card. */
export function SheetEmpty({ children }: { children: ReactNode }) {
  return <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">{children}</p>;
}

/** Class of a list that holds its rows in one card, separated by a line. */
export const SHEET_LIST_CLASS = "divide-y divide-border rounded-lg border border-border bg-surface";
