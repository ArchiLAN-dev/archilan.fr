import { formatScalar, parseGameOptions } from "./yaml-options-view";

// game_options is a literal dict of named sub-settings; the read-only view used to render it via
// String(value) → "[object Object]". formatScalar must render readable pairs instead.
describe("formatScalar", () => {
  test("renders a literal dict as readable pairs, never [object Object]", () => {
    const out = formatScalar({ battle_scene: "on", default_player_name: "player_name", text_frame: 1 });
    expect(out).not.toContain("[object Object]");
    expect(out).toContain("Default Player Name: player_name");
    expect(out).toContain("Text Frame: 1");
  });

  test("booleans render as Oui/Non and arrays as a comma list", () => {
    expect(formatScalar(true)).toBe("Oui");
    expect(formatScalar(false)).toBe("Non");
    expect(formatScalar(["Sword", "Shield"])).toBe("Sword, Shield");
  });

  test("scalars pass through", () => {
    expect(formatScalar("player_name")).toBe("player_name");
    expect(formatScalar(1)).toBe("1");
  });
});

describe("parseGameOptions", () => {
  test("resolves the game block and preserves a literal dict value", () => {
    const yaml = [
      "name: t",
      "game: Pokemon Platinum",
      "Pokemon Platinum:",
      "  game_options:",
      "    default_player_name: player_name",
      "    text_frame: 1",
      "",
    ].join("\n");

    const opts = parseGameOptions(yaml, "Pokemon Platinum");
    expect(opts).not.toBeNull();
    expect(opts?.game_options).toEqual({ default_player_name: "player_name", text_frame: 1 });
  });
});
