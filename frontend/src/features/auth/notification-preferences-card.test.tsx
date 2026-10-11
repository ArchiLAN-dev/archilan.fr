import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { NOTIFICATION_PREFERENCES_KEY, NotificationPreferencesCard } from "./notification-preferences-card";
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

/** Story 43.11b: per type, the bell and the devices, the bell only, or nothing. */
describe("notification preferences card", () => {
  it("shows each type with its three choices and the current one checked", () => {
    const html = render([
      { type: "friend_activity", channel: "bell", discord: null },
      { type: "run_invitation", channel: "bell_push", discord: null },
    ]);

    expect(html).toContain("Activité de mes amis favoris");
    expect(html).toContain("Invitations dans une run");
    expect(html.match(/role="radio"/g)).toHaveLength(6);
    expect(html).toContain('aria-checked="true" class="min-h-9 cursor-pointer rounded-full border px-3 text-xs font-semibold transition-colors border-accent bg-accent/15 text-accent-text" role="radio" type="button">Cloche seulement');
    expect(html).not.toContain("Discord");
  });

  it("offers Discord once it is linked, except for a type that reaches nothing", () => {
    const html = render([
      { type: "run_started", channel: "bell_push", discord: true },
      { type: "friend_activity", channel: "bell", discord: false },
      { type: "run_nudge", channel: "none", discord: true },
    ]);

    expect(html.match(/Aussi en message privé sur Discord/g)).toHaveLength(2);
    expect(html.match(/type="checkbox"/g)).toHaveLength(2);
    expect(html.match(/checked=""/g)).toHaveLength(1);
  });
});
