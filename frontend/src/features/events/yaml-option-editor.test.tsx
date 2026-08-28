import { renderToStaticMarkup } from "react-dom/server";

import { YamlOptionEditor } from "./yaml-option-editor";
import type { OptionTypesMap } from "@/lib/archipelago-yaml";

/**
 * Archipelago renders a `NamedRange` with **named** keys plus a note, not numeric ones:
 *
 *     ending:
 *       random: 0
 *       the_end: 50   # equivalent to 100
 *       50: 50
 *
 * Story 9.33 made the introspected type authoritative, so such an option became a range instead of
 * falling through to `choice`. That is what let a fixed number be typed into it (issue #483) - but
 * the range editor only ever drew numeric rows and the four random aliases, so the named value went
 * invisible while keeping the weight the template gave it, and being written back on save. A player
 * changed the numbers and the named value kept rolling, with nothing on screen to explain why.
 *
 * The previous story's test asserted the named key survived in the parsed *model*. It did. That
 * said nothing about what the player could see, which is the part that mattered.
 */
function render(node: React.ReactElement): string {
  return renderToStaticMarkup(node);
}

const NAMED_RANGE_YAML = `name: Alice
game: Reventure
Reventure:
  ending:
    # you can define additional values
    random: 0
    the_end: 50   # equivalent to 100
    50: 50
`;

const AS_RANGE: OptionTypesMap = { ending: { type: "range", min: 1, max: 100, default: 50 } };

describe("YamlOptionEditor - valeurs nommées d'une range", () => {
  test("la valeur nommée est affichée, avec son poids modifiable", () => {
    const html = render(<YamlOptionEditor defaultYaml={NAMED_RANGE_YAML} optionTypes={AS_RANGE} playerYaml={null} />);

    expect(html).toContain("Valeurs nommées");
    expect(html).toContain("The End");
    // Its weight is the one the template gave it, and it now sits in an editable field rather than
    // in a value nobody could reach.
    const section = html.slice(html.indexOf("Valeurs nommées"), html.indexOf("Valeurs numériques"));
    expect(section).toContain('value="50"');
  });

  /** The template's note ("equivalent to 100") reaches the row, behind the usual info affordance. */
  test("la note du gabarit est portée par la ligne nommée", () => {
    const html = render(<YamlOptionEditor defaultYaml={NAMED_RANGE_YAML} optionTypes={AS_RANGE} playerYaml={null} />);

    const section = html.slice(html.indexOf("Valeurs nommées"), html.indexOf("Valeurs numériques"));
    expect(section).toContain("Description de l&#x27;option");
  });

  test("les valeurs numériques et aléatoires restent affichées à côté", () => {
    const html = render(<YamlOptionEditor defaultYaml={NAMED_RANGE_YAML} optionTypes={AS_RANGE} playerYaml={null} />);

    expect(html).toContain("Valeur 50");
    expect(html).toContain("Valeurs aléatoires");
  });

  /** A plain range has no named value, and must not grow an empty section. */
  test("une range sans valeur nommée n'affiche pas la section", () => {
    const yaml = `name: Alice
game: G
G:
  logic:
    random: 0
    50: 50
`;

    const html = render(
      <YamlOptionEditor
        defaultYaml={yaml}
        optionTypes={{ logic: { type: "range", min: 0, max: 100, default: 50 } }}
        playerYaml={null}
      />,
    );

    expect(html).not.toContain("Valeurs nommées");
    expect(html).toContain("Valeur 50");
  });

  /** The parameterised random aliases had the same blind spot, and it predates story 9.33. */
  test("un alias aléatoire paramétré est affiché lui aussi", () => {
    const yaml = `name: Alice
game: G
G:
  logic:
    random-range-0-360: 50
    50: 50
`;

    const html = render(
      <YamlOptionEditor
        defaultYaml={yaml}
        optionTypes={{ logic: { type: "range", min: 0, max: 360, default: 50 } }}
        playerYaml={null}
      />,
    );

    expect(html).toContain("Valeurs nommées");
    expect(html).toContain("Aléatoire [0–360]");
  });
});

/**
 * Story 9.51. A sub-setting of an `OptionDict` used to be eleven free text fields in a row, with
 * the accepted vocabulary living only in the option's prose. When the apworld declares a `schema`,
 * the editor can offer it - but only for the sub-settings it actually declared, and never as a
 * closed list: `text_frame` takes 1-20 *or* `random`, and `default_player_name: custom` expects a
 * name typed by hand.
 */
const DICT_YAML = `name: Alice
game: Pokemon Platinum
Pokemon Platinum:
  game_options:
    battle_style: shift
    default_player_name: player_name
    text_speed: turbo
`;

const DICT_SCHEMA: OptionTypesMap = {
  game_options: {
    type: "dict",
    min: 0,
    max: 0,
    default: null,
    keys: {
      battle_style: { values: ["shift", "set"] },
      text_speed: { values: ["mid", "fast"] },
    },
  },
};

describe("YamlOptionEditor - valeurs déclarées d'une sous-option de dict", () => {
  test("une sous-option déclarée devient une liste, sur sa valeur courante", () => {
    const html = render(<YamlOptionEditor defaultYaml={DICT_YAML} optionTypes={DICT_SCHEMA} playerYaml={null} />);

    expect(html).toContain('<option value="shift" selected="">shift</option>');
    expect(html).toContain('<option value="set">set</option>');
  });

  test("la liste garde toujours une sortie libre", () => {
    // Une déclaration exacte n'est pas forcément exhaustive. Fermer la liste transformerait une
    // information juste en contrainte fausse, sans recours pour le joueur.
    const html = render(<YamlOptionEditor defaultYaml={DICT_YAML} optionTypes={DICT_SCHEMA} playerYaml={null} />);

    expect(html).toContain("Autre…");
  });

  test("une sous-option non déclarée garde son champ texte", () => {
    const html = render(<YamlOptionEditor defaultYaml={DICT_YAML} optionTypes={DICT_SCHEMA} playerYaml={null} />);

    expect(html).toContain('value="player_name"');
    expect(html).not.toContain('<option value="player_name"');
  });

  test("une valeur enregistrée hors liste est conservée, pas ramenée au défaut", () => {
    // `text_speed: turbo` n'est dans aucune liste - un YAML écrit avant l'introspection, ou saisi
    // à la main. Il reste tel quel, dans le champ libre, avec « Autre… » sélectionné.
    const html = render(<YamlOptionEditor defaultYaml={DICT_YAML} optionTypes={DICT_SCHEMA} playerYaml={null} />);

    expect(html).toContain('value="turbo"');
    expect(html).toContain('<option value="__archilan_custom__" selected="">Autre…</option>');
    expect(html).not.toContain('<option value="mid" selected=""');
  });

  test("sans déclaration, le dict rend exactement ce qu'il rendait avant", () => {
    const html = render(
      <YamlOptionEditor
        defaultYaml={DICT_YAML}
        optionTypes={{ game_options: { type: "dict", min: 0, max: 0, default: null } }}
        playerYaml={null}
      />,
    );

    expect(html).not.toContain("Autre…");
    expect(html).toContain('value="shift"');
  });
});
