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

  it("refuses an archive that is not a zip", () => {
    expect(() => PackFiles.fromZip(strToU8("not a zip"), "", "")).toThrow(PackError);
  });

  it("looks in the variant folder first, then at the root", () => {
    const files = PackFiles.fromEntries({ "layouts/a.json": "root", "compact/layouts/a.json": "variant", "layouts/b.json": "b" }, "compact");
    expect(files.text("layouts/a.json")).toBe("variant");
    expect(files.text("layouts/b.json")).toBe("b");
    expect(PackFiles.fromEntries({ "layouts/a.json": "root" }, "standard").text("layouts/a.json")).toBe("root");
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
