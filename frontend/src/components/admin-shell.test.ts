import { SIDEBAR_STORAGE_KEY, readSidebarCollapsed, sidebarLayout, writeSidebarCollapsed } from "./admin-shell";

function memoryStorage(initial: Record<string, string> = {}) {
  const values = new Map(Object.entries(initial));
  return {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => {
      values.set(key, value);
    },
  };
}

const throwing = {
  getItem: () => {
    throw new Error("blocked");
  },
  setItem: () => {
    throw new Error("blocked");
  },
};

describe("sidebar preference (story 3.19)", () => {
  it("is full when nothing is stored, when storage is missing or when it throws", () => {
    expect(readSidebarCollapsed(memoryStorage())).toBe(false);
    expect(readSidebarCollapsed(null)).toBe(false);
    expect(readSidebarCollapsed(throwing)).toBe(false);
  });

  it("remembers the collapsed choice and the way back", () => {
    const storage = memoryStorage();
    writeSidebarCollapsed(storage, true);
    expect(storage.getItem(SIDEBAR_STORAGE_KEY)).toBe("collapsed");
    expect(readSidebarCollapsed(storage)).toBe(true);
    writeSidebarCollapsed(storage, false);
    expect(readSidebarCollapsed(storage)).toBe(false);
  });

  it("does not throw when storage is blocked", () => {
    expect(() => writeSidebarCollapsed(throwing, true)).not.toThrow();
    expect(() => writeSidebarCollapsed(null, true)).not.toThrow();
  });
});

describe("sidebarLayout (story 3.19)", () => {
  it("shows the full sidebar at desktop width and the rail on tablets only", () => {
    expect(sidebarLayout(false)).toEqual({ full: "hidden lg:flex", rail: "hidden md:flex lg:hidden", main: "lg:ml-60" });
  });

  it("keeps the rail at desktop width once collapsed and gives the width back to the content", () => {
    expect(sidebarLayout(true)).toEqual({ full: "hidden", rail: "hidden md:flex", main: "lg:ml-12" });
  });
});
