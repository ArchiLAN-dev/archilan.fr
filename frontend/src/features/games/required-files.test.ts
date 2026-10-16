import { asGameRequiredFiles, requiredFileLabel } from "./required-files";

const DKC3 = {
  setting: "DK3Settings.rom_file",
  file: "dkc3.sfc",
  extension: ".sfc",
  description: "DKC3 (US) ROM File",
  md5s: ["120abf304f0c40fe059f6a192ed4f947"],
  neededAtGeneration: true,
};

describe("required files (story 44.3)", () => {
  it("keeps the well-formed entries only", () => {
    expect(asGameRequiredFiles([DKC3, { setting: "x" }, null, { ...DKC3, md5s: [1] }])).toEqual([DKC3]);
    expect(asGameRequiredFiles(undefined)).toEqual([]);
  });

  it("names a file by the world's description, else by its expected name", () => {
    expect(requiredFileLabel(DKC3)).toBe("DKC3 (US) ROM File");
    expect(requiredFileLabel({ ...DKC3, description: null })).toBe("dkc3.sfc");
  });
});
