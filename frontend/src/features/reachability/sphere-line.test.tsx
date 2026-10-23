import { renderToStaticMarkup } from "react-dom/server";

import { SphereLine } from "./sphere-line";
import type { SphereEntry } from "./types";

const spheres: SphereEntry[] = [{
  index: 0,
  status: "current",
  counts: { total: 1, checked: 0, reachable: 1, blocked: 0 },
  locations: [{ id: 1, name: "Cave", check_status: "reachable", item: { id: 7, name: "Sword", flags: 1, slot: 2, slot_name: "Bob" } }],
}];

/** Story 27.12: the spheres shown to a player name the locations, never what they hold. */
describe("SphereLine", () => {
  it("shows each location's item when allowed", () => {
    const html = renderToStaticMarkup(<SphereLine currentSlot={1} spheres={spheres} />);
    expect(html).toContain("Cave");
    expect(html).toContain("Sword");
  });

  it("hides the items, even when the data carries them", () => {
    const html = renderToStaticMarkup(<SphereLine currentSlot={1} hideItems spheres={spheres} />);
    expect(html).toContain("Cave");
    expect(html).not.toContain("Sword");
    expect(html).not.toContain("Bob");
  });
});
