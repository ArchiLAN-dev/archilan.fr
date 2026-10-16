import {
  acceptedFiles,
  hasRefusedFile,
  checkProvidedFile,
  filesToProvide,
  launchBlocker,
  md5OfFile,
  providedFileMessage,
  requiredFilesForm,
  REQUIRED_FILE_MAX_BYTES,
} from "./required-file-check";
import type { GameRequiredFile } from "./required-files";

// MD5("abc") = 900150983cd24fb0d6963f7d28e17f72
const ABC_MD5 = "900150983cd24fb0d6963f7d28e17f72";

const ROM: GameRequiredFile = {
  setting: "DK3Settings.rom_file",
  file: "dkc3.sfc",
  extension: ".sfc",
  description: "DKC3 (US) ROM File",
  md5s: [ABC_MD5],
  neededAtGeneration: true,
};

function file(content: string, name = "dkc3.sfc"): File {
  return new File([content], name);
}

describe("provided file check (story 44.5)", () => {
  it("hashes a file by slices", async () => {
    const progress: number[] = [];
    expect(await md5OfFile(file("abc"), (f) => progress.push(f))).toBe(ABC_MD5);
    expect(progress.at(-1)).toBe(1);
  });

  it("accepts the expected file and names the expected one otherwise", async () => {
    expect(await checkProvidedFile(ROM, file("abc"))).toMatchObject({ kind: "accepted", md5: ABC_MD5 });

    const wrong = await checkProvidedFile(ROM, file("abd"));
    expect(wrong.kind).toBe("wrong");
    expect(providedFileMessage(wrong)).toContain("« DKC3 (US) ROM File » attendu");
  });

  it("checks nothing when the world lists no MD5, and refuses a file too large", async () => {
    expect(await checkProvidedFile({ ...ROM, md5s: [] }, file("anything"))).toMatchObject({ kind: "accepted", md5: null });

    const huge = { size: REQUIRED_FILE_MAX_BYTES + 1 } as unknown;
    expect(await checkProvidedFile(ROM, huge as File)).toEqual({ kind: "tooLarge" });
  });

  it("only asks for the files a generation proved necessary", () => {
    expect(filesToProvide([ROM, { ...ROM, setting: "Other.rom", neededAtGeneration: false }])).toEqual([ROM]);
  });

  it("blocks the launch until every needed file is accepted", async () => {
    expect(launchBlocker([ROM], {})).toBe("Fournis le fichier « DKC3 (US) ROM File » pour lancer la partie.");
    expect(launchBlocker([ROM], { [ROM.setting]: { kind: "checking", progress: 0.5 } })).toBe("Vérification du fichier en cours…");

    const accepted = await checkProvidedFile(ROM, file("abc"));
    expect(launchBlocker([ROM], { [ROM.setting]: accepted })).toBeNull();
    expect(Object.keys(acceptedFiles({ [ROM.setting]: accepted }))).toEqual([ROM.setting]);
  });

  it("builds the multipart body the API reads", () => {
    const form = requiredFilesForm({ [ROM.setting]: file("abc", "Donkey Kong Country 3 (USA).sfc") });

    const sent = form.get("requiredFiles[DK3Settings.rom_file]");
    expect(sent).toBeInstanceOf(File);
    expect(sent instanceof File ? sent.name : "").toBe("Donkey Kong Country 3 (USA).sfc");
  });
});

/** Review of epic 44. */
describe("required file check, review fixes", () => {
  const rom = { setting: "DK3Settings.rom_file", file: "dkc3.sfc", extension: ".sfc", description: null, md5s: ["900150983CD24FB0D6963F7D28E17F72"], neededAtGeneration: true };

  it("accepts the right file whatever the case of the declared MD5", async () => {
    // md5("abc") = 900150983cd24fb0d6963f7d28e17f72
    const state = await checkProvidedFile(rom, new File(["abc"], "dkc3.sfc"));
    expect(state.kind).toBe("accepted");
  });

  it("sends only the files still needed", () => {
    const states = {
      "DK3Settings.rom_file": { kind: "accepted" as const, file: new File(["a"], "a.sfc"), md5: null },
      "Gone.rom_file": { kind: "accepted" as const, file: new File(["b"], "b.sfc"), md5: null },
    };
    expect(Object.keys(acceptedFiles(states, [rom]))).toEqual(["DK3Settings.rom_file"]);
  });

  it("knows when a chosen file can be taken back", () => {
    expect(hasRefusedFile({ a: { kind: "wrong", md5: "x", expected: "DKC3" } })).toBe(true);
    expect(hasRefusedFile({ a: { kind: "checking", progress: 0 } })).toBe(false);
  });
});
