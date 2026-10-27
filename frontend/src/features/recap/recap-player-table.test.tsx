import { renderToStaticMarkup } from "react-dom/server";

import type { PlayerRow } from "./build-player-rows";
import { RecapPlayerTable } from "./recap-player-table";

function row(slotId: string, playedSeconds: number): PlayerRow {
  return {
    slotId,
    slotName: slotId,
    label: `player-${slotId}`,
    game: "G",
    rank: null,
    checksDone: 0,
    sentToOthers: 0,
    receivedFromOthers: 0,
    kept: 0,
    completionSeconds: null,
    playedSeconds,
    wasReleased: false,
    isInvalidated: false,
    badges: [],
  };
}

describe("RecapPlayerTable connected time (story 32.26)", () => {
  it("shows the connected time of each slot, a dash for one never seen connected", () => {
    const html = renderToStaticMarkup(
      <RecapPlayerTable colorBySlotName={new Map()} rows={[row("a", 5400), row("b", 0)]} />,
    );

    expect(html).toContain("Connecté");
    expect(html).toContain("1 h 30 min");
    expect(html).toMatch(/>-<\/td><\/tr>/);
  });

  it("hides the column for a run played before the time was counted", () => {
    const html = renderToStaticMarkup(
      <RecapPlayerTable colorBySlotName={new Map()} rows={[row("a", 0), row("b", 0)]} />,
    );

    expect(html).not.toContain("Connecté");
  });
});
