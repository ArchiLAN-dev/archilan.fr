import { renderToStaticMarkup } from "react-dom/server";

import { HintsPanel } from "./hints-panel";
import type { HintEntry, HintsData } from "./types";

function render(node: React.ReactElement): string {
  return renderToStaticMarkup(node);
}

const MY_SLOT = 2;

function hint(over: Partial<HintEntry> = {}): HintEntry {
  return {
    receivingPlayer: MY_SLOT,
    receivingPlayerName: "Alice",
    findingPlayer: 3,
    findingPlayerName: "Bob",
    locationId: 100,
    locationName: "Une salle",
    itemId: 200,
    itemName: "Une clé",
    itemFlags: 1,
    entrance: "",
    found: false,
    status: 0,
    statusName: "unspecified",
    ...over,
  };
}

function data(hints: HintEntry[]): HintsData {
  return { slot: MY_SLOT, hints, hintsUsed: hints.length, hintPointsAvailable: 50, hintCost: 10 };
}

/**
 * Story 9.50. The player could already rank a hint (stories 9.34/9.35) but everything landed back in
 * the same list, so the ranking they had just done was exactly what they could no longer find.
 *
 * These assert the shape rendered on first paint - the filters themselves are interactive, so what a
 * server-rendered test can pin is which controls appear, and what the counter says.
 */
describe("HintsPanel filters", () => {
  test("no filter is active on open: the list is what it was before the story", () => {
    const html = render(
      <HintsPanel
        data={data([
          hint({ locationId: 1, statusName: "priority" }),
          hint({ locationId: 2, statusName: "avoid" }),
        ])}
      />,
    );

    // Bare total, not "n / m": nothing is being hidden.
    expect(html).toContain(">2<");
    expect(html).not.toContain("2 / 2");
    // Every hint is rendered.
    expect(html.match(/Une clé/g)).toHaveLength(2);
  });

  test("both new axes appear when the hints differ on them", () => {
    const html = render(
      <HintsPanel
        data={data([
          hint({ locationId: 1, statusName: "priority" }),
          hint({ locationId: 2, statusName: "avoid", receivingPlayer: 5, findingPlayer: MY_SLOT }),
        ])}
      />,
    );

    expect(html).toContain("Priorité des indices");
    expect(html).toContain("Prioritaires");
    expect(html).toContain("Côté des indices");
    expect(html).toContain("Dans mon monde");
  });

  /** Five empty priority chips on a slot where nobody ranked anything are noise, not a control. */
  test("an axis whose hints all share one value is not shown", () => {
    const html = render(
      <HintsPanel data={data([hint({ locationId: 1 }), hint({ locationId: 2 })])} />,
    );

    expect(html).not.toContain("Priorité des indices");
    expect(html).not.toContain("Côté des indices");
    // The state filter it already had is untouched.
    expect(html).toContain("En attente");
  });

  test("the empty list stays the one the panel already had when nothing was ever hinted", () => {
    const html = render(<HintsPanel data={data([])} />);

    expect(html).toContain("Aucun indice demandé pour ce slot.");
    expect(html).not.toContain("Réinitialiser les filtres");
  });

  /** A found hint loses its ranking to `found`, so some combinations legitimately match nothing. */
  test("a found hint reports the found status rather than the priority it had", () => {
    const html = render(
      <HintsPanel data={data([hint({ found: true, status: 40, statusName: "found" })])} />,
    );

    expect(html).toContain("Trouvé");
  });
});
