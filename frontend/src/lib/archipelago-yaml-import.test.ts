import { checkImportedYaml, IMPORT_MAX_BYTES } from "./archipelago-yaml-import";
import { buildItemCatalog } from "./item-catalog";
import { parseDefaultYaml, serializeToYaml, type GameOption, type OptionTypesMap, type ParsedYaml } from "./archipelago-yaml";

/** A trimmed Hollow Knight template, in the weighted shape Archipelago writes. */
const DEFAULT_YAML = `name: Player{number}
game: Hollow Knight
requires:
  version: 0.6.8
Hollow Knight:
  progression_balancing:
    random: 0
    50: 50
  RandomizeSkills:
    false: 0
    true: 50
  RandomizeGrubs:
    false: 50
    true: 0
  Goal:
    any: 50
    hollowknight: 0
    radiance: 0
  StartingGeo:
    random: 0
    0: 50
  RandomCharmCosts:
    random: 0
    vanilla: 50 # equivalent to -1
    shuffle: 0 # equivalent to -2
  start_hints: []
  start_location_hints: []
  start_inventory: {}
`;

const OPTION_TYPES: OptionTypesMap = {
  progression_balancing: { type: "range", min: 0, max: 99, default: 50 },
  StartingGeo: { type: "range", min: 0, max: 1000, default: 0 },
  RandomCharmCosts: { type: "range", min: 0, max: 240, default: -1, values: ["vanilla", "shuffle"] },
  RandomizeSkills: { type: "toggle", min: 0, max: 0, default: null },
  RandomizeGrubs: { type: "toggle", min: 0, max: 0, default: null },
};

function base(): ParsedYaml {
  const parsed = parseDefaultYaml(DEFAULT_YAML, OPTION_TYPES);
  if (parsed === null) throw new Error("default yaml fixture must parse");
  return parsed;
}

function option(parsed: ParsedYaml | null, key: string): GameOption {
  const found = parsed?.options.find((o) => o.key === key);
  if (found === undefined) throw new Error(`option ${key} missing`);
  return found;
}

const HAND_WRITTEN = `name: HKHebdo
game: Hollow Knight
Hollow Knight:
  Goal: hollowknight
  RandomizeSkills: true
  RandomizeGrubs: false
  StartingGeo: 300
  RandomCharmCosts: vanilla
  start_hints:
    - Mantis_Claw
    - Lurien
`;

describe("checkImportedYaml - un YAML valide", () => {
  test("les valeurs écrites à la main sont converties au modèle de l'éditeur", () => {
    const check = checkImportedYaml(HAND_WRITTEN, base());

    expect(check.errors).toEqual([]);
    expect(check.warnings).toEqual([]);
    expect(check.result?.playerName).toBe("HKHebdo");
    expect(option(check.result, "Goal")).toMatchObject({
      choices: [
        { value: "any", weight: 0 },
        { value: "hollowknight", weight: 50 },
        { value: "radiance", weight: 0 },
      ],
    });
    expect(option(check.result, "RandomizeSkills")).toMatchObject({ weightFalse: 0, weightTrue: 50 });
    expect(option(check.result, "RandomizeGrubs")).toMatchObject({ weightFalse: 50, weightTrue: 0 });
    expect(option(check.result, "StartingGeo")).toMatchObject({ entries: [{ key: "300", weight: 50 }] });
    expect(option(check.result, "RandomCharmCosts")).toMatchObject({ entries: [{ key: "vanilla", weight: 50 }] });
    expect(option(check.result, "start_hints")).toMatchObject({ items: ["Mantis_Claw", "Lurien"] });
  });

  test("une option absente du fichier garde sa valeur par défaut", () => {
    const defaults = base();
    const check = checkImportedYaml(HAND_WRITTEN, defaults);

    expect(option(check.result, "progression_balancing")).toBe(option(defaults, "progression_balancing"));
  });

  test("le résultat se resérialise en YAML que l'éditeur relit à l'identique", () => {
    const check = checkImportedYaml(HAND_WRITTEN, base());
    const yamlOut = serializeToYaml(check.result as ParsedYaml);

    expect(yamlOut).toContain("hollowknight: 50");
    expect(yamlOut).toContain("'300': 50");
    expect(checkImportedYaml(yamlOut, base()).errors).toEqual([]);
  });

  test("les pondérations, les alias aléatoires et les options communes au premier niveau sont acceptés", () => {
    const check = checkImportedYaml(
      `name: Bob
game:
  Hollow Knight: 1
start_hints: [Lurien]
Hollow Knight:
  Goal:
    any: 10
    Radiance: 30
  StartingGeo:
    random-range-100-200: 50
  RandomizeSkills:
    "on": 25
    "off": 75
`,
      base(),
    );

    expect(check.errors).toEqual([]);
    expect(option(check.result, "Goal")).toMatchObject({ choices: [{ weight: 10 }, { weight: 0 }, { value: "radiance", weight: 30 }] });
    expect(option(check.result, "StartingGeo")).toMatchObject({ entries: [{ key: "random-range-100-200", isCustom: true }] });
    expect(option(check.result, "RandomizeSkills")).toMatchObject({ weightFalse: 75, weightTrue: 25 });
    expect(option(check.result, "start_hints")).toMatchObject({ items: ["Lurien"] });
  });

  test("sans nom dans le fichier, le nom du slot est conservé", () => {
    const check = checkImportedYaml("game: Hollow Knight\nHollow Knight:\n  Goal: any\n", base());

    expect(check.result?.playerName).toBe("Player{number}");
  });
});

describe("checkImportedYaml - valeurs qu'Archipelago accepte sous une autre forme", () => {
  test("des poids au-delà de 100 gardent leurs proportions, avec une remarque", () => {
    const check = checkImportedYaml(`game: Hollow Knight
Hollow Knight:
  Goal:
    any: 300
    radiance: 100
    hollowknight: 1
`, base());

    expect(check.errors).toEqual([]);
    expect(option(check.result, "Goal")).toMatchObject({
      choices: [
        { value: "any", weight: 100 },
        { value: "hollowknight", weight: 1 },
        { value: "radiance", weight: 33 },
      ],
    });
    expect(check.warnings).toEqual([
      { path: "Hollow Knight.Goal", message: "Goal : poids ramenés entre 0 et 100 en gardant les proportions (l'éditeur ne va pas au-delà)." },
    ]);
  });

  test("des poids jusqu'à 100 sont repris tels quels, sans remarque", () => {
    const check = checkImportedYaml(`game: Hollow Knight
Hollow Knight:
  StartingGeo:
    100: 7
    300: 93
`, base());

    expect(option(check.result, "StartingGeo")).toMatchObject({ entries: [{ key: "100", weight: 7 }, { key: "300", weight: 93 }] });
    expect(check.warnings).toEqual([]);
  });

  test("random sur un choix devient des poids égaux entre tous les choix", () => {
    const check = checkImportedYaml(`game: Hollow Knight
Hollow Knight:
  Goal: random
`, base());

    expect(check.errors).toEqual([]);
    expect(option(check.result, "Goal")).toMatchObject({ choices: [{ weight: 17 }, { weight: 17 }, { weight: 17 }] });
    expect(check.warnings[0]?.message).toContain("« random » converti en poids égaux entre tous les choix");
  });

  test("random pondéré sur un interrupteur répartit son poids entre true et false", () => {
    const check = checkImportedYaml(`game: Hollow Knight
Hollow Knight:
  RandomizeSkills:
    random: 40
    true: 10
`, base());

    expect(option(check.result, "RandomizeSkills")).toMatchObject({ weightFalse: 20, weightTrue: 30 });
  });

  test("le nombre d'une valeur nommée hors bornes retombe sur son nom", () => {
    const check = checkImportedYaml(`game: Hollow Knight
Hollow Knight:
  RandomCharmCosts: -1
`, base());

    expect(check.errors).toEqual([]);
    expect(option(check.result, "RandomCharmCosts")).toMatchObject({ entries: [{ key: "vanilla", weight: 50, isCustom: false }] });
  });
});

describe("checkImportedYaml - avertissements", () => {
  test("une option inconnue et une section étrangère sont signalées puis ignorées", () => {
    const check = checkImportedYaml(
      `name: Bob
game: Hollow Knight
Hollow Knight:
  Goal: any
  NotAnOption: true
A Link to the Past:
  goal: ganon
`,
      base(),
    );

    expect(check.errors).toEqual([]);
    expect(check.warnings).toEqual([
      { path: "A Link to the Past", message: "Section inconnue, ignorée." },
      { path: "Hollow Knight.NotAnOption", message: "Option inconnue de ce jeu, ignorée." },
    ]);
    expect(check.result).not.toBeNull();
  });

  test("un lieu inconnu est signalé quand la liste des lieux est connue", () => {
    const check = checkImportedYaml(
      "game: Hollow Knight\nHollow Knight:\n  start_location_hints:\n    - Mantis_Claw\n    - Nowhere\n",
      base(),
      { locationNames: ["Mantis_Claw", "Lurien"] },
    );

    expect(check.errors).toEqual([]);
    expect(check.warnings).toEqual([{ path: "Hollow Knight.start_location_hints", message: "Lieux inconnus : Nowhere." }]);
  });
});

describe("checkImportedYaml - noms d'items (story 9.58)", () => {
  const catalog = buildItemCatalog(["Lurien", "Mantis_Claw"], { Dreamers: ["Lurien"] });

  test("un item inconnu du jeu est signalé, sans bloquer", () => {
    const check = checkImportedYaml(`game: Hollow Knight
Hollow Knight:
  start_hints:
    - Lurien
    - Dreamers
    - Mantis Claw
  start_inventory:
    Mantis_Claw: 1
    Grub: 3
`, base(), { itemCatalog: catalog });

    expect(check.errors).toEqual([]);
    expect(check.result).not.toBeNull();
    expect(check.warnings).toEqual([
      { path: "Hollow Knight.start_hints", message: "Items inconnus de ce jeu : Mantis Claw." },
      { path: "Hollow Knight.start_inventory", message: "Items inconnus de ce jeu : Grub." },
    ]);
  });

  test("sans liste d'items, rien n'est signalé", () => {
    const check = checkImportedYaml(`game: Hollow Knight
Hollow Knight:
  start_hints: [Anything]
`, base());

    expect(check.warnings).toEqual([]);
  });
});

describe("checkImportedYaml - erreurs bloquantes", () => {
  test.each([
    ["", "Le fichier est vide."],
    ["game: [unclosed", "YAML illisible"],
    ["name: A\n---\nname: B\n", "plusieurs YAML"],
    ["- a\n- b\n", "doit être un dictionnaire"],
  ])("document %j refusé", (text, message) => {
    const check = checkImportedYaml(text, base());

    expect(check.result).toBeNull();
    expect(check.errors[0]?.message).toContain(message);
  });

  test("un fichier trop gros est refusé", () => {
    const check = checkImportedYaml(`# ${"x".repeat(IMPORT_MAX_BYTES)}\ngame: Hollow Knight\n`, base());

    expect(check.errors[0]?.message).toBe("Le fichier dépasse 100 Ko.");
  });

  test.each([
    ["Hollow Knight:\n  Goal: any\n", "Le champ « game » est absent."],
    ["game: Celeste\nHollow Knight:\n  Goal: any\n", "Ce YAML est pour « Celeste », pas pour « Hollow Knight »."],
    ["game:\n  Hollow Knight: 0\n  Celeste: 50\nHollow Knight: {}\n", "Ce YAML ne choisit pas « Hollow Knight »."],
    ["game:\n  Hollow Knight: 50\n  Celeste: 50\nHollow Knight: {}\n", "plusieurs jeux (Celeste)"],
  ])("jeu incorrect %j", (text, message) => {
    const check = checkImportedYaml(text, base());

    expect(check.result).toBeNull();
    expect(check.errors.find((e) => e.path === "game")?.message).toContain(message);
  });

  test("une section du jeu absente est une erreur", () => {
    const check = checkImportedYaml("game: Hollow Knight\n", base());

    expect(check.errors).toContainEqual({ path: "Hollow Knight", message: "La section « Hollow Knight: » des options du jeu est absente." });
  });

  test("un nom de slot invalide est une erreur", () => {
    const check = checkImportedYaml("name: Jean Pierre\ngame: Hollow Knight\nHollow Knight: {}\n", base());

    expect(check.errors.map((e) => e.path)).toEqual(["name"]);
  });

  test.each([
    ["Goal: godhome", "« godhome » n'est pas un des choix connus de l'éditeur (any, hollowknight, radiance)"],
    ["Goal: 1", "« 1 » est le numéro d'un choix : écris plutôt son nom"],
    ["Goal: random-low", "ne s'applique qu'aux options numériques"],
    ["RandomizeSkills: random-high", "ne s'applique qu'aux options numériques"],
    ["RandomCharmCosts: -3", "-3 est hors limites (0 à 240)"],
    ["RandomizeSkills: maybe", "« maybe » n'est pas une valeur d'interrupteur (true, false ou random)"],
    ["StartingGeo: 5000", "5000 est hors limites (0 à 1000)"],
    ["StartingGeo: 2.5", "2.5 n'est pas un nombre entier"],
    ["StartingGeo: lots", "« lots » n'est ni un nombre ni une valeur nommée"],
    ["Goal:\n    any: 0\n    radiance: 0", "tous les poids sont à 0"],
    ["Goal:\n    any: beaucoup", "le poids de « any » doit être un nombre positif"],
    ["start_hints: Lurien", "une liste est attendue"],
    ["start_inventory: [Lurien]", "un dictionnaire « clé: valeur » est attendu"],
  ])("valeur incompatible %j", (line, message) => {
    const check = checkImportedYaml(`game: Hollow Knight\nHollow Knight:\n  ${line}\n`, base());

    expect(check.result).toBeNull();
    expect(check.errors).toHaveLength(1);
    expect(check.errors[0]?.path.startsWith("Hollow Knight.")).toBe(true);
    expect(check.errors[0]?.message).toContain(message);
  });
});
