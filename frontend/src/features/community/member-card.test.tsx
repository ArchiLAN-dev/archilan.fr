import { renderToStaticMarkup } from "react-dom/server";

import { MemberCard } from "./member-card";
import type { DirectoryRow } from "./community-directory-api";

/**
 * Story 30.39 : la carte joueur porte le badge Live quand le membre diffuse. Le badge est un lien
 * vers sa chaîne, donc il ne peut pas vivre dans le lien de la carte - c'est ce que ce test garde.
 */
function row(overrides: Partial<DirectoryRow> = {}): DirectoryRow {
  return {
    slug: "alice",
    displayName: "Alice",
    avatarUrl: null,
    level: 4,
    xp: 420,
    xpIntoLevel: 20,
    xpForNextLevel: 100,
    playing: false,
    liveTwitchLogin: null,
    ...overrides,
  };
}

describe("MemberCard", () => {
  test("un membre en direct porte un lien vers sa chaîne", () => {
    const html = renderToStaticMarkup(<MemberCard row={row({ liveTwitchLogin: "alicestream" })} />);

    expect(html).toContain('href="https://twitch.tv/alicestream"');
    expect(html).toContain('target="_blank"');
    expect(html).toContain("Live");
  });

  test("le badge n'est pas imbriqué dans le lien de la carte", () => {
    const html = renderToStaticMarkup(<MemberCard row={row({ liveTwitchLogin: "alicestream" })} />);

    const cardLink = html.indexOf('href="/joueurs/alice"');
    const cardLinkEnd = html.indexOf("</a>", cardLink);
    const twitchLink = html.indexOf('href="https://twitch.tv/alicestream"');

    expect(twitchLink).toBeGreaterThan(cardLinkEnd);
  });

  test("un membre sans lien Twitch garde la carte d'aujourd'hui", () => {
    const html = renderToStaticMarkup(<MemberCard row={row()} />);

    expect(html).not.toContain("twitch.tv");
    expect(html).not.toContain("Live");
  });

  /** AC 4 : les deux signaux cohabitent, et c'est le cas intéressant. */
  test("en jeu et en direct coexistent", () => {
    const html = renderToStaticMarkup(<MemberCard row={row({ playing: true, liveTwitchLogin: "alicestream" })} />);

    expect(html).toContain('aria-label="En jeu"');
    expect(html).toContain("https://twitch.tv/alicestream");
  });
});
