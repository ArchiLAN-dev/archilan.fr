import { http, HttpResponse } from "msw";
import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { BlindTestJoinButton } from "./blind-test-join-button";
import { AdminBlindTestCard, wholeNumber } from "./admin-blind-test-card";
import { fetchBlindTestEntryUrl, isBlindTestSettings, requestBlindTestLogin, saveBlindTestSettings } from "./blind-test-api";
import { blindTestLoginReturnTo } from "./blind-test-login-bridge";

describe("blind test (story 41.40)", () => {
  it("reads the settings shape", () => {
    expect(isBlindTestSettings({ enabled: true, pellesPerSource: 1, capPerPlayer: 50, consoleUrl: null })).toBe(true);
    expect(isBlindTestSettings({ enabled: true, pellesPerSource: 1, capPerPlayer: 50, consoleUrl: "https://bt.test/admin?event=e-1" })).toBe(true);
    expect(isBlindTestSettings({ enabled: true, pellesPerSource: 1, capPerPlayer: 50 })).toBe(false);
    expect(isBlindTestSettings({ enabled: "yes", pellesPerSource: 1, capPerPlayer: 50, consoleUrl: null })).toBe(false);
  });

  it("takes whole numbers only from the form", () => {
    expect(wholeNumber(" 12 ")).toBe(12);
    expect(wholeNumber("1.5")).toBeNull();
    expect(wholeNumber("")).toBeNull();
  });

  it("gets the way into the app, or nothing when the member may not play", async () => {
    server.use(
      http.get(`${TEST_API_BASE_URL}/events/e-1/blind-test/access`, () => HttpResponse.json({ data: { url: "https://bt.test/connexion" } })),
      http.get(`${TEST_API_BASE_URL}/events/e-2/blind-test/access`, () => HttpResponse.json({ error: { code: "not_registered" } }, { status: 403 })),
    );

    await expect(fetchBlindTestEntryUrl("e-1")).resolves.toBe("https://bt.test/connexion");
    await expect(fetchBlindTestEntryUrl("e-2")).resolves.toBeNull();
  });

  it("follows the return address the server gives, and relays its refusals (story 41.45)", async () => {
    server.use(
      http.post(`${TEST_API_BASE_URL}/events/e-1/blind-test/codes`, () => HttpResponse.json({ data: { redirect: "https://bt.test/connexion/retour?code=c&state=s" } })),
      http.post(`${TEST_API_BASE_URL}/events/e-2/blind-test/codes`, () =>
        HttpResponse.json({ error: { code: "not_registered", message: "Seuls les inscrits de l'événement jouent au blind test." } }, { status: 403 }),
      ),
    );

    await expect(requestBlindTestLogin("e-1", "state-of-the-app-1234")).resolves.toEqual({ kind: "ok", redirect: "https://bt.test/connexion/retour?code=c&state=s" });
    await expect(requestBlindTestLogin("e-2", "state-of-the-app-1234")).resolves.toEqual({
      kind: "error",
      message: "Seuls les inscrits de l'événement jouent au blind test.",
    });
  });

  it("comes back to the same login after signing in", () => {
    expect(blindTestLoginReturnTo("e-1", "abc_DEF-123")).toBe("/blind-test/connexion?event=e-1&state=abc_DEF-123");
  });

  it("relays the server's message when the settings are refused", async () => {
    server.use(
      http.put(`${TEST_API_BASE_URL}/admin/events/e-1/blind-test`, () =>
        HttpResponse.json({ error: { code: "invalid_blind_test_settings", message: "Réglages du blind test invalides." } }, { status: 422 }),
      ),
    );

    await expect(saveBlindTestSettings("e-1", { enabled: true, pellesPerSource: 0, capPerPlayer: 5 })).resolves.toEqual({
      kind: "error",
      message: "Réglages du blind test invalides.",
    });
  });

  it("shows the button only with a link", () => {
    const client = new QueryClient();
    client.setQueryData(["blind-test-entry", "e-1"], "https://bt.test/connexion");
    const html = renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <BlindTestJoinButton eventId="e-1" />
        <BlindTestJoinButton eventId="e-2" />
      </QueryClientProvider>,
    );

    expect(html.match(/Jouer au blind test/g)).toHaveLength(1);
    expect(html).toContain('href="https://bt.test/connexion"');
  });

  it("gives the admin the event's id and, once the app is configured, its console (story 41.46)", () => {
    const client = new QueryClient();
    client.setQueryData(["admin-blind-test", "e-1"], { enabled: true, pellesPerSource: 1, capPerPlayer: 50, consoleUrl: "https://bt.test/admin?event=e-1" });
    client.setQueryData(["admin-blind-test", "e-2"], { enabled: false, pellesPerSource: 1, capPerPlayer: 50, consoleUrl: null });
    const render = (eventId: string) =>
      renderToStaticMarkup(
        <QueryClientProvider client={client}>
          <AdminBlindTestCard eventId={eventId} />
        </QueryClientProvider>,
      );

    const configured = render("e-1");
    expect(configured).toContain("<code");
    expect(configured).toContain('href="https://bt.test/admin?event=e-1"');
    expect(render("e-2")).not.toContain("Ouvrir la console");
  });
});
