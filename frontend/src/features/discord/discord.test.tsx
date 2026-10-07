import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { fetchDiscordStats, inviteCode, isDiscordInvite } from "./discord-api";
import { DiscordHeaderLink, DiscordNudge, DiscordServerCard, DiscordStatsLine } from "./discord-promo";

const stats = { members: 95, online: 48 };

/** Story 30.50: the ArchiLAN Discord put forward, with its live counts. */
describe("discord", () => {
  test("reads the code of an invite link", () => {
    expect(inviteCode("https://discord.gg/bVGmDcv2dE")).toBe("bVGmDcv2dE");
    expect(inviteCode("https://discord.com/invite/8Z65BR2")).toBe("8Z65BR2");
    expect(inviteCode("https://example.org")).toBeNull();
  });

  test("keeps only an invite that carries its counts", () => {
    expect(isDiscordInvite({ approximate_member_count: 95, approximate_presence_count: 48 })).toBe(true);
    expect(isDiscordInvite({ approximate_member_count: 95 })).toBe(false);
  });

  test("asks Discord for the counts, and shows none when it does not answer", async () => {
    server.use(http.get("https://discord.com/api/v10/invites/:code", () => HttpResponse.json({ approximate_member_count: 95, approximate_presence_count: 48 })));
    expect(await fetchDiscordStats()).toEqual(stats);

    server.use(http.get("https://discord.com/api/v10/invites/:code", () => new HttpResponse(null, { status: 429 })));
    expect(await fetchDiscordStats()).toBeNull();
  });

  test("the header button says how many are online, when known", () => {
    expect(renderToStaticMarkup(<DiscordHeaderLink stats={stats} />)).toContain("48 membres en ligne");
    expect(renderToStaticMarkup(<DiscordHeaderLink stats={null} />)).toContain('aria-label="Rejoindre le Discord ArchiLAN (nouvel onglet)"');
  });

  test("the counts line and the server card draw the numbers, or leave them out", () => {
    expect(renderToStaticMarkup(<DiscordStatsLine stats={stats} />)).toContain("95 membres");
    expect(renderToStaticMarkup(<DiscordStatsLine stats={null} />)).toBe("");
    const card = renderToStaticMarkup(<DiscordServerCard stats={null} />);
    expect(card).toContain("Rejoindre le serveur");
    expect(card).not.toContain("en ligne");
  });

  test("a nudge opens the Discord in a new tab", () => {
    const html = renderToStaticMarkup(
      <DiscordNudge cta="Poser ma question sur Discord" stats={stats} title="Une question sur l'événement ?">
        Texte
      </DiscordNudge>,
    );
    expect(html).toContain('target="_blank"');
    expect(html).toContain("48 en ligne");
  });
});
