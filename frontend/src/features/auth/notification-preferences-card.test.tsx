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
      { type: "friend_activity", channel: "bell" },
      { type: "run_invitation", channel: "bell_push" },
    ]);

    expect(html).toContain("Activité de mes amis favoris");
    expect(html).toContain("Invitations dans une run");
    expect(html.match(/role="radio"/g)).toHaveLength(6);
    expect(html).toContain('aria-checked="true" class="min-h-9 cursor-pointer rounded-full border px-3 text-xs font-semibold transition-colors border-accent bg-accent/15 text-accent-text" role="radio" type="button">Cloche seulement');
  });
});
