import { classifyGenerationFailure, generationFailureCopy } from "./generation-failure";

/** Story 38.16: the messages GenerationFailureParser (API) leaves, sorted into what a person can act on. */
describe("classifyGenerationFailure", () => {
  it.each([
    ["Ce jeu demande le fichier « DKC3 (US) ROM File » (.sfc) à la génération. Le lanceur doit le fournir au lancement de la partie.", "required_file"],
    ["FileNotFoundError: dkc3.sfc does not exist, but DK3Settings.rom_file is required", "required_file"],
    ["FileNotFoundError: [Errno 2] No such file or directory: '/Archipelago/data/ranked_maps.json'", "missing_file"],
    ["Fichier YAML invalide.", "invalid_yaml"],
    ["yaml.scanner.ScannerError: mapping values are not allowed here", "invalid_yaml"],
    ["Options.OptionError: Mega Man 2 has an invalid value for weapon_damage: 12", "invalid_option"],
    ["Exception: 'turbo' is not a valid option for Goal", "invalid_option"],
    ["Fill.FillError: Could not access required locations for accessibility check. [… 312 entrées …]", "fill"],
    ["No more spots to place Progressive Sword", "fill"],
    ["preflight timed out after 5m0s: context deadline exceeded", "timeout"],
    ["La génération a échoué à cause de ce slot : Fill.FillError: boom", "fill"],
    ["AttributeError: 'MultiWorld' object has no attribute 'architect'", "unknown"],
    ["", "unknown"],
  ])("%s -> %s", (message, kind) => {
    expect(classifyGenerationFailure(message)).toBe(kind);
  });
});

describe("generationFailureCopy", () => {
  it("speaks to the player about their slot and to the admin about the apworld", () => {
    expect(generationFailureCopy("fill", "player").advice).toMatch(/options/i);
    expect(generationFailureCopy("fill", "admin").advice).toMatch(/template|apworld/i);
    expect(generationFailureCopy("required_file", "player").title).toBe("Fichier requis manquant");
  });
});
