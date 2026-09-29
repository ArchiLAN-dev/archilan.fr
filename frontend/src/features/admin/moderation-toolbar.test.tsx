import { renderToStaticMarkup } from "react-dom/server";

import { ModerationToolbar, SegmentedControl } from "./moderation-toolbar";

const noop = () => undefined;

function toolbar(overrides: Partial<Parameters<typeof ModerationToolbar>[0]> = {}): string {
  return renderToStaticMarkup(
    <ModerationToolbar
      chips={[
        { key: "targetType", label: "Profils" },
        { key: "search", label: "« spam »" },
      ]}
      filterCount={1}
      filters={<p>panneau</p>}
      onRemoveChip={noop}
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
 * Story 39.12. One toolbar for both moderation tabs: search first, a filters button that says how many are
 * on, the sort on the side, then the active filters as chips and the number of results.
 */
describe("ModerationToolbar", () => {
  test("search comes first and keeps the current query", () => {
    const html = toolbar();

    expect(html).toMatch(/<input[^>]*type="search"[^>]*value="spam"|<input[^>]*value="spam"[^>]*type="search"/);
    expect(html.indexOf('type="search"')).toBeLessThan(html.indexOf("Filtres"));
    expect(html.indexOf("Filtres")).toBeLessThan(html.indexOf("Gravité"));
  });

  test("the filters button counts the active filters", () => {
    expect(toolbar()).toMatch(/Filtres<span[^>]*>1<\/span>/);
    expect(toolbar({ filterCount: 0 })).not.toMatch(/Filtres<span/);
  });

  test("each active filter is a removable chip, with a reset", () => {
    const html = toolbar();

    expect(html).toContain('aria-label="Retirer le filtre Profils"');
    expect(html).toContain('aria-label="Retirer le filtre « spam »"');
    expect(html).toContain("Réinitialiser");
  });

  test("no chip, no reset", () => {
    expect(toolbar({ chips: [] })).not.toContain("Réinitialiser");
  });

  test("the number of results sits above the list, and the sort shows its choice", () => {
    const html = toolbar();

    expect(html).toContain("12 signalements");
    expect(html).toMatch(/<option selected="" value="severity">Gravité<\/option>|<option value="severity" selected="">Gravité<\/option>/);
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
