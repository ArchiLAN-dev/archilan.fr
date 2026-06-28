import {
  eventIdOfFact,
  eventScopedFact,
  factLabel,
  isEventScopedFact,
} from "./admin-achievement-event-scope";
import type { AchievementFormOptions } from "./admin-achievements-api";

const options: AchievementFormOptions = {
  facts: [
    { key: "runs", label: "Parties jouées" },
    { key: "eventsWithGoal", label: "Événements avec objectif atteint" },
  ],
  operators: [">="],
  groupOps: ["all"],
  events: [{ id: "evt-1", title: "ArchiLAN #3" }],
};

describe("event scope helpers", () => {
  it("detects event-scoped facts", () => {
    expect(isEventScopedFact("eventsWithGoal")).toBe(true);
    expect(isEventScopedFact("event_goal:evt-1")).toBe(true);
    expect(isEventScopedFact("runs")).toBe(false);
  });

  it("extracts the event id from a scoped fact", () => {
    expect(eventIdOfFact("event_goal:evt-1")).toBe("evt-1");
    expect(eventIdOfFact("eventsWithGoal")).toBeNull();
  });

  it("emits the right fact key for a selected event", () => {
    expect(eventScopedFact("")).toBe("eventsWithGoal");
    expect(eventScopedFact("evt-1")).toBe("event_goal:evt-1");
  });

  it("resolves a scoped fact to the event title (or a deleted marker)", () => {
    expect(factLabel("event_goal:evt-1", options)).toBe("Objectif - ArchiLAN #3");
    expect(factLabel("event_goal:gone", options)).toBe("Objectif - événement supprimé");
    expect(factLabel("runs", options)).toBe("Parties jouées");
  });
});
