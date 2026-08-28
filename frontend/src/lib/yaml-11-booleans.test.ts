import { parseDefaultYaml, serializeToYaml } from "./archipelago-yaml";
import { quoteYaml11Booleans } from "./yaml-11-booleans";

/**
 * Nous écrivons le YAML avec js-yaml, qui suit YAML 1.2 : `on` y est une chaîne, donc émise sans
 * guillemets. Archipelago le relit avec PyYAML, resté en YAML 1.1, où `on` est un booléen.
 *
 * Signalé en production sur Pokemon Platinum : `ValueError: invalid battle scene: "False"`. Le
 * fichier était valide des deux côtés - ce sont les deux versions de la norme qui divergent, et
 * c'est nous qui écrivons.
 */
describe("quoteYaml11Booleans", () => {
  test.each(["on", "off", "yes", "no", "y", "n", "On", "OFF", "Yes", "NO"])(
    "cite la valeur ambiguë %s",
    (token) => {
      expect(quoteYaml11Booleans(`  battle_scene: ${token}`)).toBe(`  battle_scene: '${token}'`);
    },
  );

  /** Une option de choix peut s'appeler « on » : la clé est aussi vulnérable que la valeur. */
  test("cite une clé ambiguë", () => {
    expect(quoteYaml11Booleans("  on: 50")).toBe("  'on': 50");
  });

  test("cite un élément de liste ambigu", () => {
    expect(quoteYaml11Booleans("  - off")).toBe("  - 'off'");
  });

  test("ne touche pas à ce qui n'est pas ambigu", () => {
    const yaml = ["name: Alice", "  text_speed: fast", "  turbo_button: only_on_demand", "  count: 12"].join("\n");

    expect(quoteYaml11Booleans(yaml)).toBe(yaml);
  });

  /** `true` et `false` sont ambigus dès YAML 1.2 : js-yaml les cite déjà, rien à refaire. */
  test("laisse les scalaires déjà cités tranquilles", () => {
    expect(quoteYaml11Booleans("  'true': 50")).toBe("  'true': 50");
    expect(quoteYaml11Booleans("  battle_scene: 'on'")).toBe("  battle_scene: 'on'");
  });

  test("laisse une clé sans valeur intacte", () => {
    expect(quoteYaml11Booleans("Pokemon Platinum:")).toBe("Pokemon Platinum:");
  });
});

describe("aller-retour complet", () => {
  /** Le cas exact remonté en production. */
  test("un dict littéral d'options garde ses on/off pour PyYAML", () => {
    const source = `name: Alice
game: Pokemon Platinum
Pokemon Platinum:
  game_options:
    battle_scene: on
    text_speed: fast
    turbo: off
`;

    const parsed = parseDefaultYaml(source, null);
    if (parsed === null) throw new Error("yaml de test invalide");
    const out = serializeToYaml(parsed);

    expect(out).toContain("battle_scene: 'on'");
    expect(out).toContain("turbo: 'off'");
    // Ce qui n'était pas ambigu ne bouge pas.
    expect(out).toContain("text_speed: fast");
  });
});
