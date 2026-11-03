import { http, HttpResponse } from "msw";
import { renderToStaticMarkup } from "react-dom/server";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { parseOverlayParams } from "@/features/overlay/overlay-params";
import { fetchMinimapperzBoard, isMinimapperzBoard, type MinimapperzBoard } from "./minimapperz-api";
import { MinimapperzOverlay, MinimapperzWall, minimapperzScreenUrl, parseMinimapperzScreenParams } from "./minimapperz-screen";

const board: MinimapperzBoard = {
  status: "ok",
  eventTitle: "ArchiLAN #4",
  ranking: [
    { rank: 1, name: "Alice", score: 9120, games: 3, wins: 2 },
    { rank: 2, name: "stranger", score: 4500.5, games: 1, wins: 0 },
  ],
  recent: [{ id: "g2", playedAt: "2026-11-07T18:00:00+00:00", winner: "Alice", players: 2 }],
};

describe("Minimapperz screen (story 49.4)", () => {
  it("reads its parameters, the event required", () => {
    const overlay = parseMinimapperzScreenParams(new URLSearchParams("event=e-1"));
    expect(overlay).toMatchObject({ mode: "overlay", eventId: "e-1", lines: 5 });
    expect(overlay.overlay.pos).toBe("top-right");
    expect(parseMinimapperzScreenParams(new URLSearchParams("event=e-1&mode=ecran")).lines).toBe(10);
    expect(parseMinimapperzScreenParams(new URLSearchParams("event=e-1&lines=3")).lines).toBe(3);
    expect(parseMinimapperzScreenParams(new URLSearchParams("event=../x")).eventId).toBeNull();
  });

  it("builds the links to copy", () => {
    expect(minimapperzScreenUrl("https://archilan.fr", { eventId: "e-1", mode: "overlay" })).toBe("https://archilan.fr/o/minimapperz?event=e-1");
    expect(minimapperzScreenUrl("https://archilan.fr", { eventId: "e-1", mode: "ecran" })).toBe("https://archilan.fr/o/minimapperz?event=e-1&mode=ecran");
  });

  it("reads the public board", async () => {
    expect(isMinimapperzBoard(board)).toBe(true);
    expect(isMinimapperzBoard({ ...board, status: "maybe" })).toBe(false);
    server.use(
      http.get(`${TEST_API_BASE_URL}/events/e-1/minimapperz/board`, () => HttpResponse.json({ data: board })),
      http.get(`${TEST_API_BASE_URL}/events/e-2/minimapperz/board`, () => HttpResponse.json({ error: { code: "event_not_found" } }, { status: 404 })),
    );
    await expect(fetchMinimapperzBoard("e-1")).resolves.toEqual(board);
    await expect(fetchMinimapperzBoard("e-2")).resolves.toBeNull();
  });

  it("shows the ranking on the overlay, and nothing without one", () => {
    const params = parseOverlayParams(new URLSearchParams());
    const html = renderToStaticMarkup(<MinimapperzOverlay board={board} lines={1} params={params} />);
    expect(html).toContain("Alice");
    expect(html).not.toContain("stranger");
    expect(renderToStaticMarkup(<MinimapperzOverlay board={{ ...board, status: "unavailable", ranking: [] }} lines={5} params={params} />)).toBe("");
  });

  it("shows the ranking and the last games on the LAN screen, or why not", () => {
    const html = renderToStaticMarkup(<MinimapperzWall board={board} lines={10} />);
    expect(html).toContain("Classement Minimapperz");
    expect(html).toContain("ArchiLAN #4");
    expect(html).toContain("Dernières parties");
    expect(html).toContain("4 500,5");
    expect(renderToStaticMarkup(<MinimapperzWall board={{ ...board, status: "unreachable" }} lines={10} />)).toContain("Minimapperz ne répond pas");
    expect(renderToStaticMarkup(<MinimapperzWall board={{ ...board, ranking: [], recent: [] }} lines={10} />)).toContain("Aucune partie terminée");
  });
});
