import { renderToStaticMarkup } from "react-dom/server";
import { GameCard, isDetailPayload, type GameDetail } from "./admin-registration-detail";

const GAME: GameDetail = {
  slotId: "slot-1",
  slotOrder: 0,
  gameId: "game-1",
  gameName: "Hollow Knight",
  isComplete: true,
  warnings: [],
  playerYaml: "name: Jean\ngame: Hollow Knight\n",
};

const PAYLOAD = {
  data: {
    registrationId: "reg-1",
    status: "reserved",
    usedPrivateAccess: false,
    createdAt: "2026-10-24T10:00:00+00:00",
    submittedAt: null,
    participant: { userId: "u-1", displayName: "Jean", email: "jean@example.org" },
    gameSelectionComplete: true,
    games: [GAME],
    payment: null,
  },
};

describe("admin registration detail (story 5.8)", () => {
  it("accepts the API shape, games carrying their player YAML", () => {
    expect(isDetailPayload(PAYLOAD)).toBe(true);
    expect(isDetailPayload({ data: { ...PAYLOAD.data, games: [{ ...GAME, playerYaml: null }] } })).toBe(true);
  });

  it("rejects a game of an unexpected shape instead of crashing later", () => {
    expect(isDetailPayload({ data: { ...PAYLOAD.data, games: [{ slotId: "slot-1" }] } })).toBe(false);
    expect(isDetailPayload({ data: { ...PAYLOAD.data, games: "nope" } })).toBe(false);
  });

  it("renders a game with its YAML", () => {
    const html = renderToStaticMarkup(<GameCard game={GAME} />);

    expect(html).toContain("Hollow Knight");
    expect(html).toContain("YAML du joueur");
    expect(html).toContain("name: Jean");
  });

  it("says when a slot has no YAML yet", () => {
    const html = renderToStaticMarkup(<GameCard game={{ ...GAME, isComplete: false, playerYaml: null }} />);

    expect(html).toContain("YAML manquant");
    expect(html).toContain("Aucun YAML enregistré pour ce slot.");
  });
});
