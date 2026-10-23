import { goalsFanfare } from "@/features/overlay/overlay-params";

import { readFanfarePref } from "./use-fanfare-preference";

// Story 16.24: the goal fanfare is off until the player picks one.

function withStorage(stored: string | null, run: () => void): void {
  const store = new Map<string, string>();
  if (stored !== null) store.set("archilan_fanfare", stored);
  const g = globalThis as Record<string, unknown>;
  g.window = {};
  g.localStorage = { getItem: (key: string) => store.get(key) ?? null };
  try {
    run();
  } finally {
    delete g.window;
    delete g.localStorage;
  }
}

describe("readFanfarePref", () => {
  it("is silent when the player never picked a fanfare", () => {
    withStorage(null, () => {
      expect(readFanfarePref()).toBe("disabled");
    });
  });

  it("is silent on the server", () => {
    expect(readFanfarePref()).toBe("disabled");
  });

  it("is silent when the stored value is unknown", () => {
    withStorage("kazoo", () => {
      expect(readFanfarePref()).toBe("disabled");
    });
  });

  it.each(["random", "ff5", "disabled"])("keeps a stored choice (%s)", (stored) => {
    withStorage(stored, () => {
      expect(readFanfarePref()).toBe(stored);
    });
  });
});

describe("goalsFanfare", () => {
  it("plays a random fanfare only with sound=1", () => {
    expect(goalsFanfare({ sound: true })).toBe("random");
    expect(goalsFanfare({ sound: false })).toBe("disabled");
  });
});
