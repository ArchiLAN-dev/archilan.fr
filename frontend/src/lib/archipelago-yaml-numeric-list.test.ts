import yaml from "js-yaml";

import { mergePlayerValues, parseDefaultYaml, serializeToYaml } from "./archipelago-yaml";

// Majora's Mask Recompiled: `link_tunic_color` is an RGB list the apworld masks with `& 0xFF`. Written back
// quoted ('30'), the generation failed with "unsupported operand type(s) for &: 'str' and 'int'".
const DEFAULT_YAML = `name: Player{number}
game: MM
MM:
  link_tunic_color:
  - 30
  - 105
  - 27
  start_inventory_from_pool:
  - '12'
`;

function tunic(serialized: string): unknown {
  const doc = yaml.load(serialized) as { MM: Record<string, unknown> };
  return doc.MM.link_tunic_color;
}

describe("numeric lists keep their numbers", () => {
  it("writes the default's numbers back as numbers", () => {
    const parsed = parseDefaultYaml(DEFAULT_YAML);
    if (!parsed) throw new Error("parse failed");

    expect(tunic(serializeToYaml(parsed))).toEqual([30, 105, 27]);
  });

  it("heals a player YAML saved with quoted numbers", () => {
    const base = parseDefaultYaml(DEFAULT_YAML);
    const player = parseDefaultYaml(DEFAULT_YAML.replace("  - 30\n  - 105\n  - 27", "  - '200'\n  - '0'\n  - '27'"));
    if (!base || !player) throw new Error("parse failed");

    expect(tunic(serializeToYaml(mergePlayerValues(base, player)))).toEqual([200, 0, 27]);
  });

  it("leaves a list of names as text, even a name that looks like a number", () => {
    const parsed = parseDefaultYaml(DEFAULT_YAML);
    if (!parsed) throw new Error("parse failed");
    const doc = yaml.load(serializeToYaml(parsed)) as { MM: Record<string, unknown> };

    expect(doc.MM.start_inventory_from_pool).toEqual(["12"]);
  });
});
