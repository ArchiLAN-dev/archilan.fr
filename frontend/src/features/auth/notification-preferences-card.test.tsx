import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { channelAfter, NOTIFICATION_PREFERENCES_KEY, NotificationPreferencesCard } from "./notification-preferences-card";
import type { NotificationPreference } from "./notification-preferences-api";

function render(data: NotificationPreference[]): string {
  const client = new QueryClient();
  client.setQueryData(NOTIFICATION_PREFERENCES_KEY, data);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <NotificationPreferencesCard />
    </QueryClientProvider>,
  );
}

function switchOf(html: string, name: string): string {
  const match = new RegExp(`<button[^>]*aria-label="${name}"[^>]*>`).exec(html);
  if (match === null) throw new Error(`no switch ${name}`);
  return match[0];
}

/** Story 43.11b, 40.7: per type, a switch for the bell, the push and Discord. */
describe("notification preferences card", () => {
  it("draws a row per type and a switch per channel, reading the stored choice", () => {
    const html = render([
      { type: "friend_activity", channel: "bell", discord: null },
      { type: "run_invitation", channel: "bell_push", discord: null },
    ]);

    expect(html).toContain("Activité de mes amis favoris");
    expect(html.match(/role="switch"/g)).toHaveLength(4);
    expect(switchOf(html, "Activité de mes amis favoris : cloche")).toContain('aria-checked="true"');
    expect(switchOf(html, "Activité de mes amis favoris : push")).toContain('aria-checked="false"');
    expect(switchOf(html, "Invitations dans une run : push")).toContain('aria-checked="true"');
    expect(html).not.toContain("Discord");
  });

  it("adds the Discord column once it is linked, and greys a row whose bell is off", () => {
    const html = render([
      { type: "run_started", channel: "bell_push", discord: true },
      { type: "run_nudge", channel: "none", discord: true },
    ]);

    expect(html).toContain(">Discord</th>");
    expect(switchOf(html, "Partie lancée : Discord")).toContain('aria-checked="true"');
    expect(switchOf(html, "Relances de mes co-joueurs : cloche")).toContain('aria-checked="false"');
    expect(switchOf(html, "Relances de mes co-joueurs : push")).toContain("disabled");
    const discord = switchOf(html, "Relances de mes co-joueurs : Discord");
    expect(discord).toContain("disabled");
    expect(discord).toContain('aria-checked="false"');
  });

  it("maps the switches back onto the stored channel", () => {
    expect(channelAfter("bell_push", "bell", false)).toBe("none");
    expect(channelAfter("none", "bell", true)).toBe("bell");
    expect(channelAfter("bell", "push", true)).toBe("bell_push");
    expect(channelAfter("bell_push", "push", false)).toBe("bell");
    expect(channelAfter("none", "push", true)).toBe("none");
  });
});
