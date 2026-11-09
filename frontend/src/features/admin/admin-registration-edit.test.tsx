import { renderToStaticMarkup } from "react-dom/server";

import type { AvailableGame } from "@/features/events/events-api";
import { apiFetch } from "../../lib/apiFetch";

import { apiErrorMessage } from "@/lib/api-error";

import { adminSlotYamlUrl, saveAdminRegistrationGames } from "./admin-registration-api";
import { GameCard, isEditableByAdmin, type GameDetail } from "./admin-registration-detail";
import { addableGames, canAddGame } from "./admin-registration-editor";

jest.mock("../../lib/apiFetch");
const mockApiFetch = jest.mocked(apiFetch);

const GAME: GameDetail = {
  slotId: "slot-1",
  slotOrder: 1,
  gameId: "game-1",
  gameName: "Hollow Knight",
  isComplete: true,
  warnings: [],
  playerYaml: "name: Jean\n",
};

function available(id: string, disabled = false): AvailableGame {
  return {
    id,
    name: id,
    slug: id,
    description: "",
    availability: "available",
    disabled,
    isApworldReady: true,
    defaultYaml: null,
    coverImageUrl: null,
    coverImageAlt: null,
    platforms: [],
    steamAppId: null,
  };
}

describe("admin edits a registration (story 5.9)", () => {
  afterEach(() => mockApiFetch.mockReset());

  it("edits an active registration only", () => {
    expect(isEditableByAdmin("reserved", "idle")).toBe(true);
    expect(isEditableByAdmin("cancelled", "idle")).toBe(false);
    expect(isEditableByAdmin("reserved", "cancelled")).toBe(false);
  });

  it("offers the YAML editor on a card only when asked", () => {
    expect(renderToStaticMarkup(<GameCard game={GAME} />)).not.toContain("Modifier le YAML");
    expect(renderToStaticMarkup(<GameCard game={GAME} onEditYaml={() => undefined} />)).toContain("Modifier le YAML");
  });

  it("adds only games still switched on, under the event's cap", () => {
    expect(addableGames([available("a"), available("b", true)]).map((game) => game.id)).toEqual(["a"]);
    const draft = [{ key: "s1", gameId: "a", gameName: "a" }];
    expect(canAddGame(draft, null)).toBe(true);
    expect(canAddGame(draft, 2)).toBe(true);
    expect(canAddGame(draft, 1)).toBe(false);
  });

  it("saves the games through the admin PATCH, in order", async () => {
    mockApiFetch.mockResolvedValue(new Response("{}", { status: 200 }));

    await expect(saveAdminRegistrationGames("ev", "reg", ["a", "b"])).resolves.toEqual({ ok: true });

    const [url, init] = mockApiFetch.mock.calls[0] ?? [];
    expect(String(url)).toContain("/admin/events/ev/registrations/reg");
    expect(init?.method).toBe("PATCH");
    expect(init?.body).toBe(JSON.stringify({ slots: [{ gameId: "a" }, { gameId: "b" }] }));
  });

  it("tells the API's reason when the games are refused", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({
      error: { code: "invalid_registration_update", message: "La modification contient des erreurs.", details: { gameIds: ["La sélection ne peut pas dépasser 1 jeu(x)."] } },
    }), { status: 422 }));

    await expect(saveAdminRegistrationGames("ev", "reg", ["a", "b"])).resolves.toEqual({
      ok: false,
      message: "La sélection ne peut pas dépasser 1 jeu(x).",
    });
  });

  it("reads an error envelope without details", () => {
    expect(apiErrorMessage({ error: { message: "L'inscription n'est plus modifiable." } })).toBe("L'inscription n'est plus modifiable.");
    expect(apiErrorMessage("nope")).toBeNull();
  });

  it("saves a slot's YAML through the admin route", () => {
    expect(adminSlotYamlUrl("ev", "reg", "slot-1")).toContain("/admin/events/ev/registrations/reg/slots/slot-1/yaml");
  });
});
