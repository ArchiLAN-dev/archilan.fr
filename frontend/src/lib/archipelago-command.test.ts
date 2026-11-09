import { adminCommand, quoteApArg, rawAdminCommand } from "./archipelago-command";

describe("Archipelago admin commands", () => {
  it("leaves plain arguments as they are", () => {
    expect(quoteApArg("Alice_2")).toBe("Alice_2");
    expect(quoteApArg("3626168")).toBe("3626168");
  });

  it("quotes what the server would split or misread, like shlex.quote", () => {
    expect(quoteApArg("Bob le Bricoleur")).toBe("'Bob le Bricoleur'");
    expect(quoteApArg("Link's House")).toBe(`'Link'"'"'s House'`);
    expect(quoteApArg("")).toBe("''");
  });

  it("quotes every argument of a split command", () => {
    expect(adminCommand("hint", "Bob le Bricoleur", "Moon Pearl")).toBe("!admin /hint 'Bob le Bricoleur' 'Moon Pearl'");
    expect(adminCommand("send_multiple", 3, "Alice", "Progressive Sword")).toBe("!admin /send_multiple 3 Alice 'Progressive Sword'");
  });

  it("never quotes the rest of a raw command", () => {
    expect(rawAdminCommand("release", "Bob le Bricoleur")).toBe("!admin /release Bob le Bricoleur");
  });
});
