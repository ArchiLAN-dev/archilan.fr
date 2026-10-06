import { renderToStaticMarkup } from "react-dom/server";

import { PushNotificationsCardView } from "./push-notifications-card";
import type { PushCardState } from "./push-support";

const noop = () => undefined;

function render(state: PushCardState): string {
  return renderToStaticMarkup(<PushNotificationsCardView busy={false} feedback={null} onDisable={noop} onEnable={noop} state={state} />);
}

/**
 * Story 40.2. The card says what can be done on this device, and how to get there when it cannot.
 */
describe("PushNotificationsCardView", () => {
  test("off: offers to turn notifications on", () => {
    expect(render("disabled")).toContain("Activer les notifications");
  });

  test("on: says so and offers to turn them off", () => {
    const html = render("enabled");

    expect(html).toContain("Activées sur cet appareil.");
    expect(html).toContain("Désactiver");
  });

  test("blocked: explains how to unblock in the browser, with no button", () => {
    const html = render("blocked");

    expect(html).toContain("bloquées pour ce site");
    expect(html).not.toContain("<button");
  });

  test("iPhone: explains how to install the site to get pushes, with no button (story 40.4)", () => {
    const html = render("ios-install");

    expect(html).toContain("Sur l&#x27;écran d&#x27;accueil");
    expect(html).toContain("reviens ici pour les activer");
    expect(html).not.toContain("<button");
  });

  test("unsupported browser and site without keys say so plainly", () => {
    expect(render("unsupported")).toContain("ne prend pas en charge");
    expect(render("unavailable")).toContain("pas encore disponibles");
  });
});
