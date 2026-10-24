import { http, HttpResponse } from "msw";
import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { BlindTestJoinButton } from "./blind-test-join-button";
import { wholeNumber } from "./admin-blind-test-card";
import { fetchBlindTestJoinUrl, isBlindTestSettings, saveBlindTestSettings } from "./blind-test-api";

describe("blind test (story 41.40)", () => {
  it("reads the settings shape", () => {
    expect(isBlindTestSettings({ enabled: true, pellesPerSource: 1, capPerPlayer: 50 })).toBe(true);
    expect(isBlindTestSettings({ enabled: "yes", pellesPerSource: 1, capPerPlayer: 50 })).toBe(false);
  });

  it("takes whole numbers only from the form", () => {
    expect(wholeNumber(" 12 ")).toBe(12);
    expect(wholeNumber("1.5")).toBeNull();
    expect(wholeNumber("")).toBeNull();
  });

  it("gets the signed link, or nothing when the member may not play", async () => {
    server.use(
      http.get(`${TEST_API_BASE_URL}/events/e-1/blind-test/join`, () => HttpResponse.json({ data: { url: "https://bt.test/play?token=abc" } })),
      http.get(`${TEST_API_BASE_URL}/events/e-2/blind-test/join`, () => HttpResponse.json({ error: { code: "not_registered" } }, { status: 403 })),
    );

    await expect(fetchBlindTestJoinUrl("e-1")).resolves.toBe("https://bt.test/play?token=abc");
    await expect(fetchBlindTestJoinUrl("e-2")).resolves.toBeNull();
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
    client.setQueryData(["blind-test-join", "e-1"], "https://bt.test/play?token=abc");
    const html = renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <BlindTestJoinButton eventId="e-1" />
        <BlindTestJoinButton eventId="e-2" />
      </QueryClientProvider>,
    );

    expect(html.match(/Jouer au blind test/g)).toHaveLength(1);
    expect(html).toContain('href="https://bt.test/play?token=abc"');
  });
});
