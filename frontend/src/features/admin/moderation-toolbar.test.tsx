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
    expect(html).toMatch(/<option selected="" value="severity">Gravité<\/option>|<option value="severity" selected="">Gravité<\/option>/);
  });

  test("the filters are always on the page, no panel to open (story 39.13)", () => {
    const html = toolbar();

    expect(html).toContain(">Cible<");
    expect(html).toContain("<select");
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

  test("names itself inside the control and shows its choice", () => {
    const html = select("any");

    expect(html).toContain(">Cible<");
    expect(html).toMatch(/<option selected="" value="any">Tous<\/option>|<option value="any" selected="">Tous<\/option>/);
  });

  test("its opened list is dark like the page, not the browser's white box", () => {
    // The select itself is transparent to blend into its frame; Chrome would paint the list from that.
    expect(select("any")).toMatch(/<select class="[^"]*\[&amp;&gt;option\]:bg-surface[^"]*\[&amp;&gt;option\]:text-foreground/);
  });

  test("stands out when it filters", () => {
    expect(select("profile")).toContain("border-accent-text/70 bg-accent/20");
    expect(select("profile")).toMatch(/<select class="[^"]*text-accent-text/);
    expect(select("any")).not.toContain("bg-accent/20");
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
