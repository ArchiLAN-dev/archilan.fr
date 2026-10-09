import { presenceLabel, presenceStateLabel, presenceTone } from "./rich-presence";

describe("rich presence (story 43.7)", () => {
  it("names the state, the game and the progress while playing", () => {
    expect(presenceLabel({ game: "Hollow Knight", slotState: "playing", progressPercent: 42 })).toBe("En jeu · Hollow Knight · 42 %");
    expect(presenceLabel({ game: "Hollow Knight", slotState: "bk", progressPercent: 60 })).toBe("En BK · Hollow Knight");
    expect(presenceLabel({ game: "Hollow Knight", slotState: "goal", progressPercent: null })).toBe("Objectif atteint · Hollow Knight");
  });

  it("shows the game only without detailed tracking, or from an older API", () => {
    expect(presenceLabel({ game: "Hollow Knight", slotState: "unknown", progressPercent: null })).toBe("En jeu · Hollow Knight");
    expect(presenceLabel({ game: null })).toBe("En jeu");
  });

  it("colours a BK and a goal apart", () => {
    expect(presenceTone({ slotState: "bk" })).toBe("bk");
    expect(presenceTone({ slotState: "goal" })).toBe("goal");
    expect(presenceTone({})).toBe("playing");
    expect(presenceStateLabel({ slotState: "goal" })).toBe("Objectif atteint");
  });
});
