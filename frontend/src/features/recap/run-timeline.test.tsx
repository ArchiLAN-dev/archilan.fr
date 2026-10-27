import { renderToStaticMarkup } from "react-dom/server";

import type { FeedEvent } from "./feed-api";
import { RunTimeline } from "./run-timeline";

function event(id: string, type: string, occurredAt: string): FeedEvent {
  return {
    id,
    type,
    text: "",
    occurredAt,
    item: { id: 1, name: "Key", flags: null },
    location: { id: Number(id), name: "Chest" },
    sender: { slot: 1, name: "kionx", game: "Game" },
    receiver: { slot: 2, name: "masterkafey", game: "Game" },
  };
}

// Noon UTC, a day apart: the same two calendar days in any test time zone.
const YESTERDAY = "2026-10-07T12:00:00Z";
const TODAY = "2026-10-08T12:00:00Z";

/**
 * Story 32.21: on an async run, a morning hint (no find yet that day) became the default day, drew no
 * series and blanked the whole timeline - pager included - until the first find of the day.
 */
describe("RunTimeline", () => {
  test("a last day holding only a hint does not blank the timeline", () => {
    const html = renderToStaticMarkup(
      <RunTimeline events={[event("1", "item-received", YESTERDAY), event("2", "hint", TODAY)]} />,
    );

    expect(html).toContain("Déroulé de la partie");
    expect(html).toContain("kionx");
  });

  test("the same holds for a goal alone on the last day", () => {
    const html = renderToStaticMarkup(
      <RunTimeline events={[event("1", "item-received", YESTERDAY), event("2", "goal", TODAY)]} />,
    );

    expect(html).toContain("Déroulé de la partie");
  });

  test("a DeathLink death reads as who died, the cause and who went down with them (story 32.25)", () => {
    const death: FeedEvent = {
      ...event("2", "death_link", TODAY),
      text: "Fell into a pit",
      receiver: { slot: null, name: null, game: null },
      victims: ["masterkafey", "Zoe"],
    };
    const html = renderToStaticMarkup(<RunTimeline events={[event("1", "item-received", TODAY), death]} />);

    expect(html).toContain("Mort");
    expect(html).toContain("Fell into a pit");
    expect(html).toContain("masterkafey, Zoe");
  });

  test("a run without any item find still renders nothing", () => {
    expect(renderToStaticMarkup(<RunTimeline events={[event("1", "hint", TODAY)]} />)).toBe("");
  });
});
