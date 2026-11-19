import { strToU8, zipSync } from "fflate";

import { PackError } from "./errors";
import { parseJsonc } from "./jsonc";
import { normalizePath, PackFiles } from "./pack-files";

describe("PackFiles", () => {
  it("strips the root folder of the zip", () => {
    const zip = zipSync({
      "pack-main/manifest.json": strToU8("{}"),
      "pack-main/scripts/init.lua": strToU8("x = 1"),
      "other/readme.md": strToU8("no"),
    });
    const files = PackFiles.fromZip(zip, "pack-main", "");
    expect(files.paths().sort()).toEqual(["manifest.json", "scripts/init.lua"]);
    expect(files.text("scripts/init.lua")).toBe("x = 1");
  });

  it("reads a zip whose manifest is at the root", () => {
    const files = PackFiles.fromZip(zipSync({ "manifest.json": strToU8("{}") }), "", "");
    expect(files.text("manifest.json")).toBe("{}");
  });

  it("refuses a zip that declares more than the server's bounds once unzipped (story 54.13)", () => {
    const zip = zipSync({ "manifest.json": strToU8("{}") }, { level: 0 });
    // The central directory entry (signature PK) declares 300 MB unzipped.
    const view = new DataView(zip.buffer, zip.byteOffset, zip.byteLength);
    const central = zip.findIndex((byte, i) => byte === 0x50 && zip[i + 1] === 0x4b && zip[i + 2] === 0x01 && zip[i + 3] === 0x02);
    view.setUint32(central + 24, 300 * 1024 * 1024, true);
    expect(() => PackFiles.fromZip(zip, "", "")).toThrow("trop gros");

    const many: Record<string, Uint8Array> = {};
    for (let i = 0; i <= 5000; i += 1) many[`f${i}`] = strToU8("");
    expect(() => PackFiles.fromZip(zipSync(many), "", "")).toThrow("trop gros");
  });

  it("refuses an archive that is not a zip", () => {
    expect(() => PackFiles.fromZip(strToU8("not a zip"), "", "")).toThrow(PackError);
  });

  it("looks in the variant folder first, then at the root", () => {
    const files = PackFiles.fromEntries({ "layouts/a.json": "root", "compact/layouts/a.json": "variant", "layouts/b.json": "b" }, "compact");
    expect(files.text("layouts/a.json")).toBe("variant");
    expect(files.text("layouts/b.json")).toBe("b");
    expect(PackFiles.fromEntries({ "layouts/a.json": "root" }, "standard").text("layouts/a.json")).toBe("root");
  });

  it("finds a file whose case differs, as Windows does, after an exact match (story 54.8)", () => {
    const files = PackFiles.fromEntries({ "locations/08Spidertown.json": "town", "Images/a.png": "upper", "images/a.png": "lower" }, "standard");
    expect(files.text("locations/08SpiderTown.json")).toBe("town");
    expect(files.text("images/a.png")).toBe("lower");
    expect(files.text("locations/missing.json")).toBeNull();
  });

  it("never leaves the pack", () => {
    const files = PackFiles.fromEntries({ "a.lua": "a" }, "");
    expect(files.text("../a.lua")).toBeNull();
    expect(files.text("C:/a.lua")).toBeNull();
    expect(files.text("./a.lua")).toBe("a");
    expect(files.text("\\a.lua")).toBe("a");
  });

  it("names the missing file", () => {
    expect(() => PackFiles.fromEntries({}, "").json("items/x.json")).toThrow(
      expect.objectContaining({ kind: "file_missing", file: "items/x.json" }),
    );
  });
});

describe("normalizePath", () => {
  it("cleans separators and dots", () => {
    expect(normalizePath("a\\b/./c//d")).toBe("a/b/c/d");
    expect(normalizePath("a/../b")).toBeNull();
  });
});

describe("parseJsonc", () => {
  it("accepts a BOM, comments and trailing commas", () => {
    expect(parseJsonc('\uFEFF{ // comment\n "a": [1, 2,], /* more */ }')).toEqual({ a: [1, 2] });
  });

  it("gives the line of a syntax error", () => {
    expect(() => parseJsonc('{\n "a": 1\n "b": 2\n}', "x.json")).toThrow(
      expect.objectContaining({ kind: "json_invalid", file: "x.json", line: 3 }),
    );
  });
});
