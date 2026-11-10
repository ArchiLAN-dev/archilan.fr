import { renderToStaticMarkup } from "react-dom/server";

import type { AvailableGame } from "@/features/events/events-api";
import { apiFetch } from "../../lib/apiFetch";

import { apiErrorMessage } from "@/lib/api-error";

import { adminSlotYamlUrl, saveAdminRegistrationGames } from "./admin-registration-api";
import { GameCard, isEditableByAdmin, type GameDetail } from "./admin-registration-detail";
import { addableGames, isAtCap } from "./admin-registration-editor";

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

  it("adds only games still switched on, and flags the event's cap without blocking it (story 5.10)", () => {
    expect(addableGames([available("a"), available("b", true)]).map((game) => game.id)).toEqual(["a"]);
    const draft = [{ key: "s1", gameId: "a", gameName: "a" }];
    expect(isAtCap(draft, null)).toBe(false);
    expect(isAtCap(draft, 2)).toBe(false);
    expect(isAtCap(draft, 1)).toBe(true);
  });

  it("saves the games through the admin PATCH, in order", async () => {
    mockApiFetch.mockResolvedValue(new Response("{}", { status: 200 }));

    await expect(saveAdminRegistrationGames("ev", "reg", ["a", "b"])).resolves.toEqual({ kind: "ok", data: true });

    const [url, init] = mockApiFetch.mock.calls[0] ?? [];
    expect(String(url)).toContain("/admin/events/ev/registrations/reg");
    expect(init?.method).toBe("PATCH");
    expect(init?.body).toBe(JSON.stringify({ slots: [{ gameId: "a" }, { gameId: "b" }], acknowledgeWarnings: false }));
  });

  it("hands back the rules to acknowledge, then replays acknowledged (story 5.10)", async () => {
    mockApiFetch.mockResolvedValueOnce(new Response(JSON.stringify({
      error: {
        code: "warnings_to_acknowledge",
        message: "Cette action dépasse des règles d'inscription : confirme pour continuer.",
        details: { warnings: [{ code: "max_games_exceeded", message: "La sélection dépasse le nombre de jeux autorisé par joueur." }] },
      },
    }), { status: 409 }));

    await expect(saveAdminRegistrationGames("ev", "reg", ["a", "b"])).resolves.toEqual({
      kind: "warnings",
      warnings: [{ code: "max_games_exceeded", message: "La sélection dépasse le nombre de jeux autorisé par joueur." }],
    });

    mockApiFetch.mockResolvedValueOnce(new Response("{}", { status: 200 }));
    await saveAdminRegistrationGames("ev", "reg", ["a", "b"], true);
    const [, init] = mockApiFetch.mock.calls[1] ?? [];
    expect(init?.body).toBe(JSON.stringify({ slots: [{ gameId: "a" }, { gameId: "b" }], acknowledgeWarnings: true }));
  });

  it("keeps a plain 409 an error, not warnings", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({
      error: { code: "inactive_registration", message: "L'inscription n'est plus modifiable.", details: [] },
    }), { status: 409 }));

    await expect(saveAdminRegistrationGames("ev", "reg", ["a"])).resolves.toEqual({
      kind: "error",
      message: "L'inscription n'est plus modifiable.",
    });
  });

  it("tells the API's reason when the games are refused", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({
      error: { code: "invalid_registration_update", message: "La modification contient des erreurs.", details: { gameIds: ["La sélection ne peut pas dépasser 1 jeu(x)."] } },
    }), { status: 422 }));

    await expect(saveAdminRegistrationGames("ev", "reg", ["a", "b"])).resolves.toEqual({
      kind: "error",
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
