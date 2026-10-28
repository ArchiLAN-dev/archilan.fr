import { renderToStaticMarkup } from "react-dom/server";

import type { PlayersSlot } from "@/features/overlay/overlay-api";

import { DeathLinkBadge, deathLinkState } from "./PlayerProgressGrid";

function slot(deathLink: Pick<PlayersSlot, "death_link_live" | "death_link_option">): PlayersSlot {
  return {
    slot_name: "Alice",
    checks_done: 3,
    checks_total: 10,
    items_received: 2,
    client_status: 20,
    goal_reached_at: null,
    ...deathLink,
  };
}

describe("DeathLink on a slot (story 9.59)", () => {
  test("a connected client with the tag shows the badge, whatever the setting says", () => {
    expect(deathLinkState(slot({ death_link_live: true, death_link_option: false }))).toBe("live");

    const html = renderToStaticMarkup(<DeathLinkBadge slot={slot({ death_link_live: true, death_link_option: null })} />);
    expect(html).toContain("DeathLink");
    expect(html).toContain('aria-label="DeathLink activé"');
  });

  test("a player offline falls back to the seed's setting", () => {
    expect(deathLinkState(slot({ death_link_live: null, death_link_option: true }))).toBe("setting");

    const html = renderToStaticMarkup(<DeathLinkBadge slot={slot({ death_link_live: null, death_link_option: true })} />);
    expect(html).toContain("joueur hors ligne");
  });

  test("turned off in the client while the setting had it: struck through", () => {
    expect(deathLinkState(slot({ death_link_live: false, death_link_option: true }))).toBe("off-in-client");

    const html = renderToStaticMarkup(<DeathLinkBadge slot={slot({ death_link_live: false, death_link_option: true })} />);
    expect(html).toContain("line-through");
    expect(html).toContain("coupé dans le client");
  });

  test("nothing when DeathLink is off or unknown", () => {
    expect(deathLinkState(slot({ death_link_live: false, death_link_option: false }))).toBeNull();
    expect(deathLinkState(slot({ death_link_live: null, death_link_option: null }))).toBeNull();
    expect(renderToStaticMarkup(<DeathLinkBadge slot={slot({ death_link_live: false, death_link_option: null })} />)).toBe("");
  });

  test("an older bridge without the fields shows nothing", () => {
    expect(deathLinkState(slot({}))).toBeNull();
  });
});
