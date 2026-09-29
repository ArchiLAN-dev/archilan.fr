import { renderToStaticMarkup } from "react-dom/server";

import { FilterSelect, ModerationToolbar, SegmentedControl } from "./moderation-toolbar";

const noop = () => undefined;

const TARGETS = [
  { value: "any", label: "Tous" },
  { value: "profile", label: "Profils" },
] as const;

function toolbar(overrides: Partial<Parameters<typeof ModerationToolbar>[0]> = {}): string {
  return renderToStaticMarkup(
    <ModerationToolbar
      active
      filters={<FilterSelect defaultValue="any" label="Cible" onChange={noop} options={[...TARGETS]} value="profile" />}
      onReset={noop}
      onSearch={noop}
      onSort={noop}
      resultLabel="12 signalements"
      search="spam"
      searchPlaceholder="Commentaire, raison ou auteur…"
      sort="severity"
      sortOptions={[
        { value: "severity", label: "Gravité" },
        { value: "recent", label: "Plus récents" },
      ]}
      {...overrides}
    />,
  );
}

/**
 * Stories 39.12 and 39.13. One toolbar for both moderation tabs: search and sort, then the filters as
 * always-visible dropdowns, a reset when something narrows the list, and the number of results.
 */
describe("ModerationToolbar", () => {
  test("search comes first and keeps the current query, the sort beside it", () => {
    const html = toolbar();

    expect(html).toMatch(/<input[^>]*type="search"[^>]*value="spam"|<input[^>]*value="spam"[^>]*type="search"/);
    expect(html.indexOf('type="search"')).toBeLessThan(html.indexOf("Gravité"));
    expect(html).toContain('aria-label="Tri : Gravité"');
  });

  test("the filters are always on the page, no panel to open (story 39.13)", () => {
    const html = toolbar();

    expect(html).toContain(">Cible<");
    expect(html).toContain('aria-label="Cible : Profils"');
    expect(html).not.toContain("Filtres");
    expect(html).not.toContain('aria-haspopup="dialog"');
  });

  test("a reset shows only when something narrows the list", () => {
    expect(toolbar()).toContain("Réinitialiser");
    expect(toolbar({ active: false })).not.toContain("Réinitialiser");
  });

  test("the number of results sits above the list", () => {
    expect(toolbar()).toContain("12 signalements");
  });
});

describe("FilterSelect", () => {
  function select(value: "any" | "profile"): string {
    return renderToStaticMarkup(<FilterSelect defaultValue="any" label="Cible" onChange={noop} options={[...TARGETS]} value={value} />);
  }

  test("uses the site's dropdown, not the browser's native select", () => {
    const html = select("any");

    expect(html).toContain('role="combobox"');
    expect(html).toContain(">Cible<");
    expect(html).toContain(">Tous<");
    expect(html).not.toMatch(/<select[^>]*class=/);
  });

  test("stands out when it filters", () => {
    expect(select("profile")).toContain("border-accent-text/70");
    expect(select("any")).not.toContain("border-accent-text/70");
  });
});

describe("SegmentedControl", () => {
  test("one radio per option, the current one checked", () => {
    const html = renderToStaticMarkup(
      <SegmentedControl
        label="Statut"
        onChange={noop}
        options={[
          { value: "pending", label: "En attente" },
          { value: "resolved", label: "Résolus" },
        ]}
        value="resolved"
      />,
    );

    expect(html).toContain('role="radiogroup"');
    expect(html).toContain('aria-label="Statut"');
    expect(html).toMatch(/aria-checked="true"[^>]*>Résolus/);
    expect(html).toMatch(/aria-checked="false"[^>]*>En attente/);
  });
});
