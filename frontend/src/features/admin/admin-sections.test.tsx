import { renderToStaticMarkup } from "react-dom/server";
import { AdminSectionTabsView } from "./admin-section-tabs";
import { ADMIN_NAV_GROUPS, ADMIN_SECTIONS, isSectionActive, sectionBadge } from "./admin-sections";

jest.mock("next/link", () => ({
  __esModule: true,
  default: ({ children, href, ...rest }: { children: React.ReactNode; href: string }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

const sections = ADMIN_NAV_GROUPS.flatMap((group) => group.sections);

describe("admin menu (story 3.20)", () => {
  it("has 12 entries", () => {
    expect(sections).toHaveLength(12);
  });

  it("keeps every page that was in the menu reachable, as an entry or a tab", () => {
    const hrefs = sections.flatMap((section) => (section.tabs ? section.tabs.map((tab) => tab.href) : [section.href]));
    for (const href of [
      "/admin", "/admin/statistiques", "/admin/evenements", "/admin/actualites", "/admin/annonces", "/admin/partenaires",
      "/admin/jeux", "/admin/utilisateurs", "/admin/adhesions", "/admin/achievements", "/admin/boutique", "/admin/quetes",
      "/admin/cadres", "/admin/bannieres", "/admin/titres", "/admin/moderation/signalements",
      "/admin/moderation/contributions", "/admin/catalogue", "/admin/sante-apworlds", "/admin/aide-archipelago",
      "/admin/weekly-runs", "/admin/sessions/config", "/admin/discord",
      // Story 53.5: the help guides, a tab of Publications.
      "/admin/aide",
    ]) {
      expect(hrefs).toContain(href);
    }
    expect(hrefs).toHaveLength(24);
  });

  it("lights the entry on any of its tabs and their sub-pages", () => {
    expect(isSectionActive("/admin/statistiques", ADMIN_SECTIONS.dashboard)).toBe(true);
    expect(isSectionActive("/admin", ADMIN_SECTIONS.dashboard)).toBe(true);
    expect(isSectionActive("/admin/quetes/types", ADMIN_SECTIONS.rewards)).toBe(true);
    expect(isSectionActive("/admin/weekly-runs/jeu/abc", ADMIN_SECTIONS.sessions)).toBe(true);
    expect(isSectionActive("/admin/evenements/e1/presences", ADMIN_SECTIONS.events)).toBe(true);
  });

  it("lights nothing else", () => {
    const lit = sections.filter((section) => isSectionActive("/admin/annonces", section));
    expect(lit.map((section) => section.key)).toEqual(["publications"]);
    expect(isSectionActive("/admin/evenements", ADMIN_SECTIONS.dashboard)).toBe(false);
  });

  it("sums the counts of an entry's tabs", () => {
    const badges = { "/admin/moderation/signalements": 2, "/admin/moderation/contributions": 3, "/admin/sante-apworlds": 1 };
    expect(sectionBadge(ADMIN_SECTIONS.moderation, badges)).toBe(5);
    expect(sectionBadge(ADMIN_SECTIONS.apworlds, badges)).toBe(1);
    expect(sectionBadge(ADMIN_SECTIONS.events, badges)).toBe(0);
  });
});

describe("AdminSectionTabsView (story 3.20)", () => {
  it("marks the current tab and shows what waits on each tab", () => {
    const html = renderToStaticMarkup(
      <AdminSectionTabsView
        badges={{ "/admin/moderation/contributions": 3 }}
        pathname="/admin/moderation/contributions/c1"
        section={ADMIN_SECTIONS.moderation}
      />,
    );
    expect(html).toContain('aria-label="Modération"');
    expect(html).toContain('href="/admin/moderation/contributions" aria-current="page"');
    expect(html).not.toContain('href="/admin/moderation/signalements" aria-current="page"');
    expect(html).toContain("3 à prendre en charge");
  });

  it("does not light the overview tab on the statistics page", () => {
    const html = renderToStaticMarkup(
      <AdminSectionTabsView badges={{}} pathname="/admin/statistiques" section={ADMIN_SECTIONS.dashboard} />,
    );
    expect(html).toContain('href="/admin/statistiques" aria-current="page"');
    expect(html).not.toContain('href="/admin" aria-current="page"');
  });

  it("renders nothing for an entry without tabs", () => {
    expect(renderToStaticMarkup(<AdminSectionTabsView badges={{}} pathname="/admin/jeux" section={ADMIN_SECTIONS.games} />)).toBe("");
  });
});
