import { http, HttpResponse } from "msw";
import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { AdminMinimapperzCard, minimapperzCheckMessage, minimapperzCollectionMessage } from "./admin-minimapperz-card";
import { PlayersBody } from "./admin-minimapperz-players";
import { pelleReasonLabel } from "./wallet-api";
import { resolveEventPellesTab } from "./admin-event-pelles-page";
import {
  checkMinimapperzConnection,
  fetchMinimapperzPlayers,
  isMinimapperzCheck,
  isMinimapperzPlayers,
  isMinimapperzSettings,
  saveMinimapperzSettings,
  type MinimapperzSettings,
} from "./minimapperz-api";

const settings: MinimapperzSettings = {
  enabled: true,
  slug: "archilan-test",
  apiKeySet: true,
  apiKeyEnd: "YW6",
  pellesPerGame: 1,
  pellesPerWin: 2,
  capPerPlayer: 50,
  collection: { at: null, status: null, games: 0, credited: 0, until: null },
};

const event = { slug: "archilan-test", name: "ArchiLAN test", opensAt: "2026-11-02T20:25:00+00:00", closesAt: "2026-11-07T20:25:00+00:00", isOpen: true };

describe("Minimapperz (story 49.1)", () => {
  it("reads the settings shape, which never carries the key", () => {
    expect(isMinimapperzSettings(settings)).toBe(true);
    expect(isMinimapperzSettings({ ...settings, slug: null, apiKeySet: false, apiKeyEnd: null })).toBe(true);
    expect(isMinimapperzSettings({ ...settings, apiKeySet: "yes" })).toBe(false);
    expect(isMinimapperzSettings({ enabled: true, slug: "x" })).toBe(false);
  });

  it("reads the outcome of a check", () => {
    expect(isMinimapperzCheck({ status: "ok", event })).toBe(true);
    expect(isMinimapperzCheck({ status: "unauthorized", event: null })).toBe(true);
    expect(isMinimapperzCheck({ status: "ok", event: null })).toBe(false);
    expect(isMinimapperzCheck({ status: "exploded", event: null })).toBe(false);
  });

  it("tells each outcome in words", () => {
    const ok = minimapperzCheckMessage({ status: "ok", event });
    expect(ok.tone).toBe("ok");
    expect(ok.text).toContain("« ArchiLAN test »");
    expect(ok.text).toContain("ouvert en ce moment");
    expect(minimapperzCheckMessage({ status: "unauthorized", event: null })).toEqual({ tone: "error", text: "Minimapperz refuse la clé : vérifie la clé et le slug de l'event." });
    expect(minimapperzCheckMessage({ status: "not_configured", event: null }).tone).toBe("error");
    expect(minimapperzCheckMessage({ status: "unreachable", event: null }).tone).toBe("error");
    expect(minimapperzCheckMessage(null).text).toBe("Test impossible. Réessaie.");
  });

  it("relays the server's message and the error of each field", async () => {
    server.use(
      http.put(`${TEST_API_BASE_URL}/admin/events/e-1/minimapperz`, () =>
        HttpResponse.json(
          {
            error: {
              code: "invalid_minimapperz_settings",
              message: "Réglages Minimapperz invalides.",
              details: { slug: ["Le slug est requis pour activer Minimapperz."], apiKey: ["La clé API est requise pour activer Minimapperz."] },
            },
          },
          { status: 422 },
        ),
      ),
    );

    await expect(
      saveMinimapperzSettings("e-1", { enabled: true, slug: "", apiKey: "", clearApiKey: false, pellesPerGame: 1, pellesPerWin: 2, capPerPlayer: 50 }),
    ).resolves.toEqual({
      kind: "error",
      message: "Réglages Minimapperz invalides.",
      fields: { slug: "Le slug est requis pour activer Minimapperz.", apiKey: "La clé API est requise pour activer Minimapperz." },
    });
  });

  it("returns the check, or null when the site fails", async () => {
    server.use(
      http.post(`${TEST_API_BASE_URL}/admin/events/e-1/minimapperz/check`, () => HttpResponse.json({ data: { status: "ok", event } })),
      http.post(`${TEST_API_BASE_URL}/admin/events/e-2/minimapperz/check`, () => HttpResponse.json({ error: { code: "forbidden" } }, { status: 403 })),
    );

    await expect(checkMinimapperzConnection("e-1")).resolves.toEqual({ status: "ok", event });
    await expect(checkMinimapperzConnection("e-2")).resolves.toBeNull();
  });

  it("shows how the key on file ends, never the key, and offers to clear it", () => {
    const client = new QueryClient();
    client.setQueryData(["admin-minimapperz", "e-1"], settings);
    client.setQueryData(["admin-minimapperz", "e-2"], { ...settings, enabled: false, slug: null, apiKeySet: false, apiKeyEnd: null });
    const render = (eventId: string) =>
      renderToStaticMarkup(
        <QueryClientProvider client={client}>
          <AdminMinimapperzCard eventId={eventId} />
        </QueryClientProvider>,
      );

    const configured = render("e-1");
    expect(configured).toContain("se termine par ...YW6");
    expect(configured).toContain('type="password"');
    expect(configured).toContain("Effacer la clé");
    expect(configured).toContain('value="archilan-test"');

    const empty = render("e-2");
    expect(empty).toContain("Aucune clé enregistrée.");
    expect(empty).not.toContain("Effacer la clé");
  });

  it("is a tab of the event's pelles while the event runs", () => {
    expect(resolveEventPellesTab("minimapperz", false)).toBe("minimapperz");
    expect(resolveEventPellesTab("minimapperz", true)).toBe("membres");
  });
});

describe("Minimapperz collection (story 49.3)", () => {
  it("tells how the last collection went", () => {
    expect(minimapperzCollectionMessage({ at: null, status: null, games: 0, credited: 0, until: null })).toBeNull();
    const ok = minimapperzCollectionMessage({ at: "2026-11-07T18:00:00+00:00", status: "ok", games: 3, credited: 7, until: "2026-11-07T17:59:00+00:00" });
    expect(ok?.tone).toBe("ok");
    expect(ok?.text).toContain("3 parties lues, 7 pelles créditées.");
    expect(minimapperzCollectionMessage({ at: "2026-11-07T18:00:00+00:00", status: "unauthorized", games: 0, credited: 0, until: null })?.text).toContain("la collecte est coupée");
    expect(minimapperzCollectionMessage({ at: "2026-11-07T18:00:00+00:00", status: "unreachable", games: 0, credited: 0, until: null })?.tone).toBe("error");
  });

  it("reads the players and lists the unlinked ones first", async () => {
    const players = {
      status: "ok",
      players: [
        { username: "stranger", code: null, games: 1, wins: 0, member: null, linked: false },
        { username: "alice_mmz", code: "MMZ-A", games: 2, wins: 1, member: "Alice", linked: true },
      ],
    };
    expect(isMinimapperzPlayers(players)).toBe(true);
    expect(isMinimapperzPlayers({ status: "boom", players: [] })).toBe(false);
    server.use(http.get(`${TEST_API_BASE_URL}/admin/events/e-1/minimapperz/players`, () => HttpResponse.json({ data: players })));
    await expect(fetchMinimapperzPlayers("e-1")).resolves.toEqual(players);

    const html = renderToStaticMarkup(<PlayersBody data={isMinimapperzPlayers(players) ? players : null} isFetching={false} />);
    expect(html).toContain("1 joueur sans code d&#x27;inscrit");
    expect(html).toContain("Non relié");
    expect(html).toContain("Alice");
    expect(renderToStaticMarkup(<PlayersBody data={{ status: "unauthorized", players: [] }} isFetching={false} />)).toContain("refuse la clé");
  });

  it("names the reason in the wallet history", () => {
    expect(pelleReasonLabel("minimapperz")).toBe("Minimapperz");
  });
});
